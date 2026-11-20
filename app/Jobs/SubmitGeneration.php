<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Generations\RecordGenerationUpdate;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public function __construct(public Generation $generation) {}

    public function handle(PixelforgeClient $pixelforge, RecordGenerationUpdate $recorder): void
    {
        $generation = $this->generation->fresh();

        // Already accepted (a webhook can beat us to it) or already given up on.
        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        try {
            // Same idempotency key on every attempt: if a previous attempt did
            // reach Pixelforge, we get that generation back instead of a new one.
            $object = $pixelforge->createGeneration($this->payload($generation), $generation->idempotency_key);
        } catch (PixelforgeException $e) {
            $this->handleError($generation, $e, $recorder);

            return;
        }

        $generation->forceFill(['submitted_at' => now()])->save();
        $recorder->apply($generation, $object);
    }

    public function failed(?Throwable $exception): void
    {
        app(RecordGenerationUpdate::class)->failLocally(
            $this->generation,
            'submission_failed',
            'We could not reach Pixelforge. You were not charged; please try again.',
        );
    }

    /**
     * Seconds to wait before the next attempt: whatever Pixelforge asks for,
     * but never less than an exponential backoff, capped at a minute.
     */
    public function backoffFor(PixelforgeException $e): int
    {
        $exponential = min(60, 2 ** $this->attempts());

        return max($e->retryAfter ?? 0, $exponential);
    }

    private function handleError(Generation $generation, PixelforgeException $e, RecordGenerationUpdate $recorder): void
    {
        if ($e->isRetryable() && $this->attempts() < $this->tries) {
            $this->release($this->backoffFor($e));

            return;
        }

        if (! $e->isRetryable() && $e->errorCode !== 'content_policy_violation') {
            Log::error('Pixelforge rejected a generation.', [
                'generation' => $generation->id,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);
        }

        $recorder->failLocally($generation, $e->errorCode, $this->messageFor($e));
    }

    private function messageFor(PixelforgeException $e): string
    {
        return match ($e->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy. You were not charged.',
            'daily_limit_exceeded' => 'The daily generation limit has been reached. It resets at midnight UTC. You were not charged.',
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded' => 'Pixelforge stayed busy after several retries. You were not charged; please try again in a minute.',
            'connection_error', 'server_error' => 'We could not reach Pixelforge. You were not charged; please try again.',
            'validation_failed' => 'Pixelforge did not accept these settings: '.$e->getMessage(),
            default => 'Something went wrong on our side. You were not charged.',
        };
    }

    /**
     * Must be identical on every attempt, otherwise Pixelforge answers 409
     * idempotency_key_reused.
     *
     * @return array<string, mixed>
     */
    private function payload(Generation $generation): array
    {
        return [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images,
            'metadata' => [
                'generation_id' => (string) $generation->id,
                'user_id' => (string) $generation->user_id,
            ],
        ];
    }
}
