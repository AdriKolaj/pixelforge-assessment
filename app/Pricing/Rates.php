<?php

namespace App\Pricing;

use InvalidArgumentException;

/**
 * USD per 1M tokens, kept as the decimal strings Pixelforge sends.
 */
final readonly class Rates
{
    public function __construct(
        public string $inputText,
        public string $inputImage,
        public string $output,
        public string $reasoning,
    ) {}

    /**
     * @param  array<string, string>  $rates
     */
    public static function fromApi(array $rates): self
    {
        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
            if (! isset($rates[$key]) || ! is_numeric($rates[$key])) {
                throw new InvalidArgumentException("Missing or invalid rate [{$key}].");
            }
        }

        return new self(
            (string) $rates['input_text_tokens'],
            (string) $rates['input_image_tokens'],
            (string) $rates['output_tokens'],
            (string) $rates['reasoning_tokens'],
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputText,
            'input_image_tokens' => $this->inputImage,
            'output_tokens' => $this->output,
            'reasoning_tokens' => $this->reasoning,
        ];
    }
}
