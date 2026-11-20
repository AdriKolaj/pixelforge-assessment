<?php

namespace App\Pricing;

final readonly class Quote
{
    public function __construct(
        public TokenUsage $estimatedUsage,
        public TokenUsage $maxUsage,
        public int $estimatedCostNano,
        public int $maxCostNano,
        public int $estimatedChargeNano,
        public int $maxChargeNano,
    ) {}
}
