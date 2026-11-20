<?php

namespace App\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class CostCalculator
{
    public function __construct(private readonly string $markup) {}

    /**
     * What Pixelforge charges us for the given usage, in nanodollars.
     *
     * Rates are USD per 1M tokens, so one token costs rate * 1000 nanodollars.
     * With rates of up to three decimals this is always a whole number, which
     * matches the nine-decimal cost_usd in Pixelforge's usage report.
     */
    public function providerCost(TokenUsage $usage, Rates $rates): int
    {
        $usdPerMillion = BigDecimal::zero()
            ->plus(BigDecimal::of($rates->inputText)->multipliedBy($usage->inputText))
            ->plus(BigDecimal::of($rates->inputImage)->multipliedBy($usage->inputImage))
            ->plus(BigDecimal::of($rates->output)->multipliedBy($usage->output))
            ->plus(BigDecimal::of($rates->reasoning)->multipliedBy($usage->reasoning));

        return $usdPerMillion
            ->multipliedBy(1000)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }

    /**
     * What we charge the user: provider cost plus markup. Anything below a
     * nanodollar is rounded up, so we never sell below cost.
     */
    public function chargeFor(int $providerCostNano): int
    {
        return BigDecimal::of($providerCostNano)
            ->multipliedBy(BigDecimal::one()->plus($this->markup))
            ->toScale(0, RoundingMode::Up)
            ->toInt();
    }

    public function quote(PricingTable $pricing, string $prompt, string $size, string $quality, int $referenceImages): Quote
    {
        // Unicode characters, not bytes.
        $characters = mb_strlen($prompt, 'UTF-8');
        $textTokens = (int) ceil($characters / $pricing->charactersPerTextToken);
        $maxTextTokens = BigDecimal::of($textTokens)
            ->multipliedBy(BigDecimal::one()->plus($pricing->textTokenVariance))
            ->toScale(0, RoundingMode::Ceiling)
            ->toInt();

        $imageTokens = $referenceImages * $pricing->tokensPerReferenceImage;
        $outputTokens = $pricing->outputTokensFor($quality, $size);
        $reasoning = $pricing->reasoningRangeFor($quality);

        $estimated = new TokenUsage(
            $textTokens,
            $imageTokens,
            $outputTokens,
            intdiv($reasoning['min'] + $reasoning['max'], 2),
        );
        $max = new TokenUsage($maxTextTokens, $imageTokens, $outputTokens, $reasoning['max']);

        $estimatedCost = $this->providerCost($estimated, $pricing->rates);
        $maxCost = $this->providerCost($max, $pricing->rates);

        return new Quote(
            $estimated,
            $max,
            $estimatedCost,
            $maxCost,
            $this->chargeFor($estimatedCost),
            $this->chargeFor($maxCost),
        );
    }
}
