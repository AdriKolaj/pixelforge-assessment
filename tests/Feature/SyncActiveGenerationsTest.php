<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;

it('polls generations that have gone quiet', function () {
    $stale = Generation::factory()->queued('gen_stale')->create();
    $stale->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();
    $fresh = Generation::factory()->queued('gen_fresh')->create();

    Http::fake([
        'pixelforge.test/v1/generations/gen_stale' => Http::response(pixelforgeGeneration($stale, 'succeeded')),
    ]);

    $this->artisan('pixelforge:sync')->assertSuccessful();

    expect($stale->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($stale->fresh()->charge_nano)->toBe(70_700_000)
        ->and($fresh->fresh()->status)->toBe(GenerationStatus::Queued);

    Http::assertSentCount(1);
});

it('ignores finished and unsubmitted generations', function () {
    Generation::factory()->succeeded(1, 2)->create(['updated_at' => now()->subHour()]);
    Generation::factory()->create(['updated_at' => now()->subHour()]);
    Http::fake();

    $this->artisan('pixelforge:sync')->assertSuccessful();

    Http::assertNothingSent();
});

it('stops early when Pixelforge rate limits us', function () {
    foreach (['gen_a', 'gen_b'] as $id) {
        Generation::factory()->queued($id)->create()->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();
    }

    Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down']], 429, ['Retry-After' => 10])]);

    $this->artisan('pixelforge:sync')->assertSuccessful();

    Http::assertSentCount(1);
});
