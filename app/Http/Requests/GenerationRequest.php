<?php

namespace App\Http\Requests;

use App\Pixelforge\GenerationOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.GenerationOptions::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::in(GenerationOptions::SIZES)],
            'quality' => ['required', Rule::in(GenerationOptions::QUALITIES)],
            'reference_images' => ['nullable', 'array', 'max:'.GenerationOptions::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add up to :max reference images.',
            'reference_images.*.url' => 'Reference images must be http(s) URLs.',
            'reference_images.*.required' => 'Remove empty reference image fields.',
        ];
    }

    /**
     * @return array{prompt: string, size: string, quality: string, reference_images: list<string>}
     */
    public function generationInput(): array
    {
        return [
            'prompt' => $this->validated('prompt'),
            'size' => $this->validated('size'),
            'quality' => $this->validated('quality'),
            'reference_images' => array_values($this->validated('reference_images') ?? []),
        ];
    }
}
