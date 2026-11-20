<?php

namespace App\Pixelforge;

/**
 * Request limits documented for POST /v1/generations. Validating them here
 * means a bad request never costs a round trip (or a retry).
 */
final class GenerationOptions
{
    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const QUALITIES = ['low', 'medium', 'high'];

    public const MAX_PROMPT_LENGTH = 4000;

    public const MAX_REFERENCE_IMAGES = 4;
}
