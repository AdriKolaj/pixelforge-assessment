<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * The response of GET /v1/pricing: current rates plus the token rules
 * needed to estimate a generation before it runs.
 */
final readonly class PricingTable
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public string $textTokenVariance,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $pricing
     */
    public static function fromApi(array $pricing): self
    {
        $tokens = $pricing['tokens'];

        return new self(
            rates: Rates::fromApi($pricing['rates']),
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            // json_decode turns 0.15 into a float; go through string so it stays exact.
            textTokenVariance: (string) $tokens['text_token_variance'],
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    public function outputTokensFor(string $quality, string $size): int
    {
        return $this->outputTokens[$quality][$size]
            ?? throw new InvalidArgumentException("No output tokens for {$quality} {$size}.");
    }

    /**
     * @return array{min: int, max: int}
     */
    public function reasoningRangeFor(string $quality): array
    {
        return $this->reasoningTokens[$quality]
            ?? throw new InvalidArgumentException("No reasoning range for {$quality}.");
    }
}
