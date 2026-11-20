<?php

namespace App\Pricing;

use UnexpectedValueException;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /**
     * @param  array<string, mixed>  $usage
     */
    public static function fromApi(array $usage): self
    {
        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
            if (! is_int($usage[$key] ?? null) || $usage[$key] < 0) {
                throw new UnexpectedValueException("Missing or invalid usage [{$key}].");
            }
        }

        return new self(
            $usage['input_text_tokens'],
            $usage['input_image_tokens'],
            $usage['output_tokens'],
            $usage['reasoning_tokens'],
        );
    }

    public function total(): int
    {
        return $this->inputText + $this->inputImage + $this->output + $this->reasoning;
    }
}
