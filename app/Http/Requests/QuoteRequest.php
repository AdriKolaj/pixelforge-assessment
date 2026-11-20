<?php

namespace App\Http\Requests;

use App\Pixelforge\GenerationOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The price only depends on the prompt length and how many reference images
 * there are, so a half-typed URL shouldn't stop us from quoting.
 */
class QuoteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['nullable', 'string', 'max:'.GenerationOptions::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::in(GenerationOptions::SIZES)],
            'quality' => ['required', Rule::in(GenerationOptions::QUALITIES)],
            'reference_image_count' => ['required', 'integer', 'min:0', 'max:'.GenerationOptions::MAX_REFERENCE_IMAGES],
        ];
    }
}
