<?php

use App\Pricing\CostCalculator;
use App\Pricing\PricingTable;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;

beforeEach(function () {
    $this->calculator = new CostCalculator('0.25');
    $this->pricing = PricingTable::fromApi(pricingFixture());
    $this->rates = $this->pricing->rates;
});

it('prices the succeeded generation from the docs exactly', function () {
    $usage = new TokenUsage(inputText: 12, inputImage: 765, output: 6450, reasoning: 3184);

    // 12*5 + 765*10 + 6450*40 + 3184*12.5 = 305510 USD per 1M tokens
    expect($this->calculator->providerCost($usage, $this->rates))->toBe(305_510_000);
});

it('matches the cost_usd of the usage report example', function () {
    $usage = new TokenUsage(inputText: 41, inputImage: 0, output: 3300, reasoning: 2950);

    expect($this->calculator->providerCost($usage, $this->rates))->toBe(169_080_000);
});

it('keeps fractional per-token rates exact', function () {
    // 12.50 per 1M is 12,500 nanodollars per token, 1 token at 0.001 is 1 nanodollar.
    $rates = new Rates('0.001', '0', '0', '12.50');

    expect($this->calculator->providerCost(new TokenUsage(1, 0, 0, 0), $rates))->toBe(1)
        ->and($this->calculator->providerCost(new TokenUsage(0, 0, 0, 1), $rates))->toBe(12_500);
});

it('adds a 25% markup and rounds sub-nanodollar remainders up', function (int $cost, int $charge) {
    expect($this->calculator->chargeFor($cost))->toBe($charge);
})->with([
    'nothing' => [0, 0],
    'exact' => [4, 5],
    'one nanodollar' => [1, 2],
    'remainder' => [305_510_001, 381_887_502],
    'docs example' => [305_510_000, 381_887_500],
]);

it('reproduces the worst case from the pricing docs', function () {
    $quote = $this->calculator->quote($this->pricing, str_repeat('a', 400), '1536x1024', 'high', 2);

    expect($quote->maxUsage)->toEqual(new TokenUsage(115, 1530, 6450, 8000))
        ->and($quote->maxCostNano)->toBe(373_875_000)
        ->and($quote->maxChargeNano)->toBe(467_343_750);
});

it('estimates with the middle of the reasoning range', function () {
    $quote = $this->calculator->quote($this->pricing, str_repeat('a', 40), '1024x1024', 'medium', 0);

    expect($quote->estimatedUsage)->toEqual(new TokenUsage(10, 0, 1100, 1100))
        // 10*5 + 1100*40 + 1100*12.5 = 57800
        ->and($quote->estimatedCostNano)->toBe(57_800_000)
        ->and($quote->estimatedChargeNano)->toBe(72_250_000);
});

it('counts unicode characters, not bytes', function () {
    // 5 characters but 20 bytes.
    $quote = $this->calculator->quote($this->pricing, '🎨🎨🎨🎨🎨', '1024x1024', 'low', 0);

    expect($quote->estimatedUsage->inputText)->toBe(2);
});

it('rounds the text token allowance up', function () {
    // 7 tokens + 15% = 8.05, which could be 9 tokens.
    $quote = $this->calculator->quote($this->pricing, str_repeat('x', 28), '1024x1024', 'low', 0);

    expect($quote->estimatedUsage->inputText)->toBe(7)
        ->and($quote->maxUsage->inputText)->toBe(9);
});

it('never estimates more than the maximum', function (string $quality, string $size, int $images) {
    $quote = $this->calculator->quote($this->pricing, 'A lighthouse on a cliff at dawn', $size, $quality, $images);

    expect($quote->estimatedChargeNano)->toBeLessThanOrEqual($quote->maxChargeNano)
        ->and($quote->estimatedCostNano)->toBeLessThan($quote->estimatedChargeNano);
})->with(['low', 'medium', 'high'])->with(['1024x1024', '1536x1024', '1024x1536'])->with([0, 4]);

it('rejects unknown sizes and qualities', function () {
    $this->calculator->quote($this->pricing, 'test', '512x512', 'medium', 0);
})->throws(InvalidArgumentException::class);
