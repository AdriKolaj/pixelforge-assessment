<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function pixelforgeError(string $code, int $status, array $headers = []): PromiseInterface
{
    return Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => "Pixelforge says {$code}."]], $status, $headers);
}

beforeEach(function () {
    $this->generation = Generation::factory()->create([
        'prompt' => 'A lighthouse at dawn',
        'reference_images' => ['https://example.com/style.png'],
    ]);
});

function runSubmit(Generation $generation, int $attempt = 1): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    app()->call([$job, 'handle']);

    return $job;
}

it('submits the generation with an idempotency key and our id in the metadata', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(
        pixelforgeGeneration($this->generation, 'queued', ['id' => 'gen_new']), 202,
    )]);

    runSubmit($this->generation)->assertNotReleased();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://pixelforge.test/v1/generations'
            && $request->hasHeader('Authorization', 'Bearer pf_test_key')
            && $request->hasHeader('Idempotency-Key', $this->generation->idempotency_key)
            && $request->data() === [
                'prompt' => 'A lighthouse at dawn',
                'size' => '1024x1024',
                'quality' => 'medium',
                'reference_images' => ['https://example.com/style.png'],
                'metadata' => [
                    'generation_id' => (string) $this->generation->id,
                    'user_id' => (string) $this->generation->user_id,
                ],
            ];
    });

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBe('gen_new')
        ->and($generation->rates)->toBe(pricingFixture()['rates'])
        ->and($generation->submitted_at)->not->toBeNull();
});

it('keeps the newer status when a webhook got there before the response', function () {
    Http::fake(function () {
        // The processing webhook lands while our request is still in flight.
        $this->generation->forceFill(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_new'])->save();

        return Http::response(pixelforgeGeneration($this->generation, 'queued', ['id' => 'gen_new']), 202);
    });

    runSubmit($this->generation);

    Http::assertSentCount(1);

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Processing);
});

it('does not submit twice', function () {
    Http::fake();
    $this->generation->forceFill(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_x'])->save();

    runSubmit($this->generation);

    Http::assertNothingSent();
});

it('retries after the delay Pixelforge asks for', function (string $code, int $status, int $retryAfter) {
    Http::fake(['pixelforge.test/v1/generations' => pixelforgeError($code, $status, ['Retry-After' => $retryAfter])]);

    runSubmit($this->generation)->assertReleased($retryAfter);

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Pending);
})->with([
    'overloaded' => ['overloaded', 503, 2],
    'concurrency limit' => ['concurrency_limit_exceeded', 429, 5],
    'rate limit' => ['rate_limit_exceeded', 429, 37],
]);

it('backs off further on every attempt, capped at a minute', function () {
    Http::fake(['pixelforge.test/v1/generations' => pixelforgeError('overloaded', 503, ['Retry-After' => 2])]);

    runSubmit($this->generation, attempt: 4)->assertReleased(16);
    runSubmit($this->generation, attempt: 9)->assertReleased(60);
});

it('retries when Pixelforge cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    runSubmit($this->generation)->assertReleased(2);
});

it('gives up after the last attempt without charging', function () {
    Http::fake(['pixelforge.test/v1/generations' => pixelforgeError('overloaded', 503, ['Retry-After' => 2])]);

    runSubmit($this->generation, attempt: 10)->assertNotReleased();

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('overloaded')
        ->and($generation->charge_nano)->toBe(0)
        ->and(CreditTransaction::count())->toBe(0);
});

it('fails right away on errors a retry cannot fix', function (string $code, int $status, string $message) {
    Http::fake(['pixelforge.test/v1/generations' => pixelforgeError($code, $status)]);

    runSubmit($this->generation)->assertNotReleased();

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe($code)
        ->and($generation->error_message)->toContain($message)
        ->and($generation->charge_nano)->toBe(0)
        ->and($generation->provider_id)->toBeNull();
})->with([
    'blocked prompt' => ['content_policy_violation', 400, 'content policy'],
    'daily limit' => ['daily_limit_exceeded', 429, 'daily generation limit'],
    'validation' => ['validation_failed', 422, 'did not accept'],
    'bad key' => ['invalid_api_key', 401, 'went wrong on our side'],
]);

it('marks the generation failed when the job itself dies', function () {
    (new SubmitGeneration($this->generation))->failed(new RuntimeException('worker killed'));

    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('submission_failed');
});
