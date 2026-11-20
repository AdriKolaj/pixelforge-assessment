<?php

namespace App\Http\Controllers\Webhooks;

use App\Generations\RecordGenerationUpdate;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController
{
    public function __invoke(Request $request, RecordGenerationUpdate $recorder): Response
    {
        $event = json_decode($request->getContent(), true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            abort(400, 'Malformed event.');
        }

        if (! str_starts_with($event['type'], 'generation.')) {
            // ping and anything new we don't handle yet.
            return response()->noContent();
        }

        $object = $event['data']['object'] ?? null;

        if (! is_array($object) || ! is_string($object['id'] ?? null)) {
            abort(400, 'Event has no generation.');
        }

        // Recording the event and applying it share one transaction: if applying
        // fails, the event isn't marked as seen and Pixelforge's retry gets a
        // second chance.
        DB::transaction(function () use ($event, $object, $recorder) {
            $isNew = WebhookEvent::query()->insertOrIgnore([
                'id' => $event['id'],
                'type' => $event['type'],
                'provider_generation_id' => $object['id'],
                'payload' => json_encode($event),
                'created_at' => now(),
            ]) === 1;

            if (! $isNew) {
                return;
            }

            $generation = $this->findGeneration($object);

            if ($generation === null) {
                Log::warning('Pixelforge event for an unknown generation.', [
                    'event' => $event['id'],
                    'generation' => $object['id'],
                ]);

                return;
            }

            $recorder->apply($generation, $object);
        });

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function findGeneration(array $object): ?Generation
    {
        $generation = Generation::query()->where('provider_id', $object['id'])->first();

        if ($generation !== null) {
            return $generation;
        }

        // The event can arrive before our create request has returned, when we
        // don't know the provider id yet. Our own id travels in the metadata.
        $localId = $object['metadata']['generation_id'] ?? null;

        if (! is_string($localId) || ! ctype_digit($localId)) {
            return null;
        }

        return Generation::query()->whereKey((int) $localId)->whereNull('provider_id')->first();
    }
}
