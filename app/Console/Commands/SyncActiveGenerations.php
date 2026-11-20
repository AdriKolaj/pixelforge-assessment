<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Generations\RecordGenerationUpdate;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Webhooks are the main channel, but delivery isn't guaranteed. Anything that
 * has been quiet for a while gets fetched directly.
 */
#[Signature('pixelforge:sync {--limit=10 : Max generations to check per run} {--stale=120 : Seconds without an update before we poll}')]
#[Description('Poll Pixelforge for generations whose webhooks seem to be missing')]
class SyncActiveGenerations extends Command
{
    public function handle(PixelforgeClient $pixelforge, RecordGenerationUpdate $recorder): int
    {
        $generations = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', now()->subSeconds((int) $this->option('stale')))
            ->oldest('updated_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($generations as $generation) {
            try {
                $object = $pixelforge->getGeneration($generation->provider_id);
            } catch (PixelforgeException $e) {
                $this->warn("{$generation->provider_id}: {$e->errorCode}");

                if ($e->isRetryable()) {
                    // Rate limited or overloaded: stop here, the next run picks up the rest.
                    break;
                }

                continue;
            }

            $updated = $recorder->apply($generation, $object);

            // Push it to the back of the queue even if nothing changed.
            $updated->touch();

            $this->line("{$generation->provider_id}: {$updated->status->value}");
        }

        return self::SUCCESS;
    }
}
