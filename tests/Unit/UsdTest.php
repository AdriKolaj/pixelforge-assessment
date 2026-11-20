<?php

use App\Support\Usd;

it('converts dollars to nanodollars without floats', function () {
    expect(Usd::fromDollars('10'))->toBe(10_000_000_000)
        ->and(Usd::fromDollars('0.000000001'))->toBe(1)
        ->and(Usd::fromDollars(25))->toBe(25_000_000_000);
});

it('prints the exact nine-decimal amount', function () {
    expect(Usd::exact(169_080_000))->toBe('0.169080000')
        ->and(Usd::exact(0))->toBe('0.000000000')
        ->and(Usd::exact(-1))->toBe('-0.000000001');
});

it('formats for display', function () {
    expect(Usd::format(72_250_000))->toBe('$0.07')
        ->and(Usd::format(72_250_000, 4))->toBe('$0.0723')
        ->and(Usd::format(-5_000_000_000))->toBe('-$5.00');
});
