<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    fakePricing();
    Queue::fake();
    $this->user = User::factory()->create(['balance_nano' => 1_000_000_000]);
});

it('sends guests to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('shows only the signed in user\'s generations', function () {
    Generation::factory()->for($this->user)->count(2)->create();
    Generation::factory()->create(['prompt' => 'someone else']);

    $this->actingAs($this->user)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio')
            ->has('generations.data', 2)
            ->where('hasActive', true)
            ->where('credits.balance_nano', 1_000_000_000)
            ->where('options.sizes', ['1024x1024', '1536x1024', '1024x1536']));
});

it('hides image links that have expired', function () {
    Generation::factory()->for($this->user)->succeeded(1, 2)->create([
        'image_url' => 'https://pixelforge.promptedit.dev/images/x.png',
        'image_url_expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->user)
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.image_expired', true));
});

describe('quote', function () {
    it('prices a generation before it is submitted', function () {
        $this->actingAs($this->user)
            ->postJson('/generations/quote', [
                'prompt' => str_repeat('a', 400),
                'size' => '1536x1024',
                'quality' => 'high',
                'reference_image_count' => 2,
            ])
            ->assertOk()
            ->assertJson([
                'max_charge_nano' => 467_343_750,
                'available_nano' => 1_000_000_000,
                'tokens' => ['input_image' => 1530, 'output' => 6450],
            ]);
    });

    it('quotes an empty prompt while the user is still typing', function () {
        $this->actingAs($this->user)
            ->postJson('/generations/quote', ['prompt' => '', 'size' => '1024x1024', 'quality' => 'low', 'reference_image_count' => 0])
            ->assertOk();
    });

    it('reports when prices are unavailable', function () {
        // Replace the pricing stub from beforeEach with an outage.
        Http::swap(new Factory);
        Http::fake(['pixelforge.test/v1/pricing' => Http::response(null, 503)]);

        $this->actingAs($this->user)
            ->postJson('/generations/quote', ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low', 'reference_image_count' => 0])
            ->assertStatus(503);
    });
});

describe('creating a generation', function () {
    it('saves it with the quoted prices and queues it', function () {
        $this->actingAs($this->user)
            ->post('/generations', [
                'prompt' => str_repeat('a', 40),
                'size' => '1024x1024',
                'quality' => 'medium',
                'reference_images' => ['https://example.com/a.png'],
            ])
            ->assertRedirect('/dashboard');

        $generation = Generation::sole();

        expect($generation->status)->toBe(GenerationStatus::Pending)
            ->and($generation->user_id)->toBe($this->user->id)
            ->and($generation->reference_images)->toBe(['https://example.com/a.png'])
            ->and($generation->estimated_charge_nano)->toBeGreaterThan(0)
            ->and($generation->max_charge_nano)->toBeGreaterThan($generation->estimated_charge_nano)
            ->and($generation->idempotency_key)->not->toBeEmpty();

        Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generation->is($generation));
    });

    it('validates the input', function (array $input, string $error) {
        $this->actingAs($this->user)
            ->post('/generations', array_merge([
                'prompt' => 'A lighthouse',
                'size' => '1024x1024',
                'quality' => 'medium',
            ], $input))
            ->assertSessionHasErrors($error);

        expect(Generation::count())->toBe(0);
    })->with([
        'missing prompt' => [['prompt' => ''], 'prompt'],
        'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
        'unknown size' => [['size' => '512x512'], 'size'],
        'unknown quality' => [['quality' => 'ultra'], 'quality'],
        'too many images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
        'not a url' => [['reference_images' => ['not a url']], 'reference_images.0'],
        'wrong scheme' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
    ]);

    it('counts the prompt length in characters, not bytes', function () {
        $this->actingAs($this->user)
            ->post('/generations', ['prompt' => str_repeat('é', 4000), 'size' => '1024x1024', 'quality' => 'low'])
            ->assertSessionHasNoErrors();
    });

    it('refuses when the worst case is not covered', function () {
        $this->user->forceFill(['balance_nano' => 10_000_000])->save();

        $this->actingAs($this->user)
            ->post('/generations', ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'high'])
            ->assertSessionHasErrors('credits');

        expect(Generation::count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('holds credits for generations that are still running', function () {
        // A high quality square image can cost up to ~$0.36 with markup.
        $this->user->forceFill(['balance_nano' => 500_000_000])->save();
        $request = ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'high'];

        $this->actingAs($this->user)->post('/generations', $request)->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post('/generations', $request)->assertSessionHasErrors('credits');

        expect(Generation::count())->toBe(1);
    });

    it('blocks users whose balance went negative', function () {
        $this->user->forceFill(['balance_nano' => -1])->save();

        $this->actingAs($this->user)
            ->post('/generations', ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertSessionHasErrors('credits');
    });
});
