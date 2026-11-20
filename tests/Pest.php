<?php

use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function pricingFixture(): array
{
    return json_decode(file_get_contents(__DIR__.'/Fixtures/pricing.json'), true);
}

/**
 * A Pixelforge generation object, as returned by the API and in webhooks.
 */
function pixelforgeGeneration(Generation $generation, string $status, array $overrides = []): array
{
    $base = [
        'id' => $generation->provider_id ?? 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'object' => 'generation',
        'status' => $status,
        'prompt' => $generation->prompt,
        'size' => $generation->size,
        'quality' => $generation->quality,
        'reference_images' => $generation->reference_images,
        'metadata' => ['generation_id' => (string) $generation->id, 'user_id' => (string) $generation->user_id],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => pricingFixture()['rates'],
        'error' => null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => in_array($status, ['processing', 'succeeded', 'failed']) ? '2026-11-04T14:03:13Z' : null,
        'completed_at' => in_array($status, ['succeeded', 'failed']) ? '2026-11-04T14:03:29Z' : null,
    ];

    if ($status === 'succeeded') {
        $base['image_url'] = 'https://pixelforge.promptedit.dev/images/'.$base['id'].'.png?expires=1&signature=abc';
        $base['image_url_expires_at'] = now()->addDay()->toIso8601ZuluString();
        // 12*5 + 0 + 1100*40 + 1000*12.5 = 56560 per 1M -> $0.05656
        $base['usage'] = [
            'input_text_tokens' => 12,
            'input_image_tokens' => 0,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1000,
            'total_tokens' => 2112,
        ];
    }

    if ($status === 'failed') {
        $base['error'] = ['code' => 'generation_failed', 'message' => 'The model could not produce an image for this prompt.'];
    }

    return array_replace($base, $overrides);
}

function webhookEvent(array $object, ?string $id = null, ?string $type = null): array
{
    return [
        'id' => $id ?? 'evt_'.Str::ulid(),
        'object' => 'event',
        'type' => $type ?? 'generation.'.$object['status'],
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $object],
    ];
}

/**
 * Posts a webhook exactly like Pixelforge does: raw JSON body plus an HMAC
 * signature over "<timestamp>.<body>".
 */
function postWebhook(array $event, ?int $timestamp = null, ?string $secret = null): TestResponse
{
    $body = json_encode($event);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? config('services.pixelforge.webhook_secret'));

    return test()->call('POST', '/webhooks/pixelforge', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
        'HTTP_PIXELFORGE_EVENT_TYPE' => $event['type'],
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], content: $body);
}

function fakePricing(): void
{
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(pricingFixture())]);
}
