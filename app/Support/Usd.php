<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Credits are US dollars. Internally every amount is an integer number of
 * nanodollars (1e-9 USD), which is the precision Pixelforge reports in.
 */
final class Usd
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    public static function fromDollars(string|int $dollars): int
    {
        return BigDecimal::of($dollars)
            ->multipliedBy(self::NANOS_PER_DOLLAR)
            ->toScale(0, RoundingMode::Unnecessary)
            ->toInt();
    }

    /**
     * Exact decimal string with nine places, same format as Pixelforge's cost_usd.
     */
    public static function exact(int $nano): string
    {
        return (string) BigDecimal::ofUnscaledValue($nano, 9);
    }

    public static function format(int $nano, int $decimals = 2): string
    {
        $amount = BigDecimal::ofUnscaledValue($nano, 9)->toScale($decimals, RoundingMode::HalfUp);

        return ($amount->isNegative() ? '-$' : '$').$amount->abs();
    }
}
