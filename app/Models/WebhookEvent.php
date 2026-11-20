<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Every Pixelforge event we have acted on, keyed by its evt_ id so a
 * redelivered event is recognised and skipped.
 *
 * @property string $id
 * @property string $type
 * @property string|null $provider_generation_id
 * @property array<string, mixed> $payload
 * @property Carbon $created_at
 */
#[Fillable(['id', 'type', 'provider_generation_id', 'payload'])]
class WebhookEvent extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
