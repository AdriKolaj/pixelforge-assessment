<?php

use App\Billing\CreditLedger;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;

beforeEach(function () {
    $this->user = User::factory()->create(['balance_nano' => 5_000_000_000]);
    $this->generation = Generation::factory()->for($this->user)->queued('gen_abc')->create();
});

describe('signature', function () {
    it('accepts a correctly signed request', function () {
        postWebhook(webhookEvent(['id' => 'x'], type: 'ping'))->assertNoContent();
    });

    it('rejects a missing signature', function () {
        $this->postJson('/webhooks/pixelforge', webhookEvent(pixelforgeGeneration($this->generation, 'processing')))
            ->assertBadRequest();

        expect($this->generation->fresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('rejects a signature made with another secret', function () {
        postWebhook(webhookEvent(pixelforgeGeneration($this->generation, 'processing')), secret: 'whsec_other')
            ->assertBadRequest();
    });

    it('rejects old timestamps to stop replays', function () {
        postWebhook(webhookEvent(pixelforgeGeneration($this->generation, 'processing')), timestamp: time() - 301)
            ->assertBadRequest();
    });

    it('rejects a body that was changed after signing', function () {
        $event = webhookEvent(pixelforgeGeneration($this->generation, 'succeeded'));
        $body = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, config('services.pixelforge.webhook_secret'));

        $tampered = str_replace('"output_tokens":1100', '"output_tokens":1', $body);

        $this->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], content: $tampered)->assertBadRequest();
    });

    it('fails closed when no secret is configured', function () {
        config(['services.pixelforge.webhook_secret' => '']);

        postWebhook(webhookEvent(['id' => 'x'], type: 'ping'), secret: '')->assertBadRequest();
    });
});

it('marks a generation as processing', function () {
    postWebhook(webhookEvent(pixelforgeGeneration($this->generation, 'processing')))->assertNoContent();

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->started_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:13Z')
        ->and($generation->charge_nano)->toBeNull();
});

it('records the exact cost and charges the user once on success', function () {
    postWebhook(webhookEvent(pixelforgeGeneration($this->generation, 'succeeded')))->assertNoContent();

    $generation = $this->generation->fresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_cost_nano)->toBe(56_560_000)
        ->and($generation->charge_nano)->toBe(70_700_000)
        ->and($generation->output_tokens)->toBe(1100)
        ->and($generation->reasoning_tokens)->toBe(1000)
        ->and($generation->image_url)->toStartWith('https://pixelforge.promptedit.dev/images/')
        ->and($generation->completed_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z')
        ->and($this->user->fresh()->balance_nano)->toBe(5_000_000_000 - 70_700_000);

    $transaction = CreditTransaction::sole();
    expect($transaction->amount_nano)->toBe(-70_700_000)
        ->and($transaction->generation_id)->toBe($generation->id);
});

it('ignores an event delivered twice', function () {
    $event = webhookEvent(pixelforgeGeneration($this->generation, 'succeeded'));

    postWebhook($event)->assertNoContent();
    postWebhook($event)->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and(CreditTransaction::count())->toBe(1)
        ->and($this->user->fresh()->balance_nano)->toBe(5_000_000_000 - 70_700_000);
});

it('charges once even if two different events report success', function () {
    // E.g. a webhook plus a resend from the dashboard, or a poll that got there first.
    postWebhook(webhookEvent(pixelforgeGeneration($this->generation, 'succeeded')))->assertNoContent();
    postWebhook(webhookEvent(pixelforgeGeneration($this->generation, 'succeeded')))->assertNoContent();

    expect(WebhookEvent::count())->toBe(2)
        ->and(CreditTransaction::count())->toBe(1)
        ->and($this->user->fresh()->balance_nano)->toBe(5_000_000_000 - 70_700_000);
});

it('never moves a finished generation backwards', function () {
    postWebhook(webhookEvent(pixelforgeGeneration($this->generation, 'succeeded')))->assertNoContent();
    postWebhook(webhookEvent(pixelforgeGeneration($this->generation, 'processing')))->assertNoContent();
    postWebhook(webhookEvent(pixelforgeGeneration($this->generation, 'failed')))->assertNoContent();

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nano)->toBe(70_700_000)
        ->and($generation->error_code)->toBeNull();
});

it('does not charge for failed generations and releases the hold', function () {
    postWebhook(webhookEvent(pixelforgeGeneration($this->generation, 'failed')))->assertNoContent();

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charge_nano)->toBe(0)
        ->and($generation->error_code)->toBe('generation_failed')
        ->and(CreditTransaction::count())->toBe(0)
        ->and(app(CreditLedger::class)->available($this->user->fresh()))->toBe(5_000_000_000);
});

it('handles an event that arrives before the create request returned', function () {
    $pending = Generation::factory()->for($this->user)->create();
    expect($pending->provider_id)->toBeNull();

    $object = pixelforgeGeneration($pending, 'processing', ['id' => 'gen_early']);
    postWebhook(webhookEvent($object))->assertNoContent();

    $pending->refresh();
    expect($pending->provider_id)->toBe('gen_early')
        ->and($pending->status)->toBe(GenerationStatus::Processing);
});

it('does not attach an event to a generation that already has another provider id', function () {
    $object = pixelforgeGeneration($this->generation, 'succeeded', ['id' => 'gen_someone_else']);

    postWebhook(webhookEvent($object))->assertNoContent();

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Queued)
        ->and(CreditTransaction::count())->toBe(0);
});

it('acknowledges events for generations it does not know', function () {
    $object = pixelforgeGeneration($this->generation, 'succeeded', [
        'id' => 'gen_unknown',
        'metadata' => [],
    ]);

    postWebhook(webhookEvent($object))->assertNoContent();

    expect(CreditTransaction::count())->toBe(0);
});

it('lets Pixelforge retry when an event cannot be applied', function () {
    $event = webhookEvent(pixelforgeGeneration($this->generation, 'succeeded', ['usage' => null]));

    $this->withoutExceptionHandling();

    expect(fn () => postWebhook($event))->toThrow(UnexpectedValueException::class)
        ->and(WebhookEvent::count())->toBe(0)
        ->and($this->generation->fresh()->status)->toBe(GenerationStatus::Queued);
});

it('trusts Pixelforge over a failure we only assumed', function () {
    // We timed out while creating it and gave up, but Pixelforge had accepted it.
    $generation = Generation::factory()->for($this->user)->create([
        'status' => GenerationStatus::Failed,
        'error_code' => 'submission_failed',
        'charge_nano' => 0,
    ]);

    postWebhook(webhookEvent(pixelforgeGeneration($generation, 'succeeded', ['id' => 'gen_late'])))->assertNoContent();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->provider_id)->toBe('gen_late')
        ->and($generation->error_code)->toBeNull()
        ->and($generation->charge_nano)->toBe(70_700_000)
        ->and(CreditTransaction::count())->toBe(1);
});

it('rejects events without a generation', function () {
    postWebhook(webhookEvent(['status' => 'succeeded'], type: 'generation.succeeded'))->assertBadRequest();
});
