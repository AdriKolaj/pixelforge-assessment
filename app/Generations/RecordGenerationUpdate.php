<?php

namespace App\Generations;

use App\Billing\CreditLedger;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\CostCalculator;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * Applies what Pixelforge tells us about a generation, wherever it comes from:
 * the create response, a webhook, or polling. All three can arrive in any
 * order and more than once, so this only ever moves a generation forward and
 * charges the user at most once.
 */
class RecordGenerationUpdate
{
    public function __construct(
        private readonly CostCalculator $calculator,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $object  a Pixelforge generation object
     */
    public function apply(Generation $generation, array $object): Generation
    {
        $incoming = GenerationStatus::tryFrom((string) ($object['status'] ?? ''));

        if ($incoming === null || $incoming === GenerationStatus::Pending) {
            throw new UnexpectedValueException('Unknown generation status ['.($object['status'] ?? 'null').'].');
        }

        return DB::transaction(function () use ($generation, $object, $incoming) {
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($generation->provider_id !== null && $generation->provider_id !== $object['id']) {
                Log::warning('Pixelforge update does not match the generation it was routed to.', [
                    'generation' => $generation->id,
                    'provider_id' => $generation->provider_id,
                    'received' => $object['id'],
                ]);

                return $generation;
            }

            if (! $this->movesForward($generation, $incoming)) {
                // Nothing to do except remember the id if this is the first time we see it.
                $generation->provider_id ??= $object['id'];
                $generation->save();

                return $generation;
            }

            $generation->forceFill([
                'provider_id' => $object['id'],
                'status' => $incoming,
                'rates' => $object['rates'] ?? $generation->rates,
                'started_at' => $this->time($object['started_at'] ?? null) ?? $generation->started_at,
                'completed_at' => $this->time($object['completed_at'] ?? null),
                // Clear any provisional local failure if Pixelforge says otherwise.
                'error_code' => null,
                'error_message' => null,
            ]);

            match ($incoming) {
                GenerationStatus::Succeeded => $this->recordSuccess($generation, $object),
                GenerationStatus::Failed => $this->recordFailure($generation, $object),
                default => null,
            };

            $generation->save();

            if ($incoming === GenerationStatus::Succeeded) {
                $this->ledger->chargeForGeneration($generation);
            }

            return $generation;
        });
    }

    /**
     * For errors on our side of the fence: Pixelforge refused the request or we
     * gave up retrying. Nothing was billed, so nothing is charged.
     */
    public function failLocally(Generation $generation, string $code, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $code, $message) {
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($generation->status !== GenerationStatus::Pending) {
                return $generation;
            }

            $generation->forceFill([
                'status' => GenerationStatus::Failed,
                'error_code' => $code,
                'error_message' => $message,
                'provider_cost_nano' => 0,
                'charge_nano' => 0,
                'completed_at' => now(),
            ])->save();

            return $generation;
        });
    }

    private function movesForward(Generation $generation, GenerationStatus $incoming): bool
    {
        // A failure we recorded before Pixelforge ever confirmed the generation
        // (e.g. a timeout) is only our best guess. If Pixelforge later reports
        // it, they were the ones who knew, and they will bill us for it.
        if ($generation->status === GenerationStatus::Failed && $generation->provider_id === null) {
            return true;
        }

        return $incoming->rank() > $generation->status->rank();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function recordSuccess(Generation $generation, array $object): void
    {
        if (! is_array($object['usage'] ?? null) || ! is_array($object['rates'] ?? null)) {
            throw new UnexpectedValueException("Succeeded generation [{$object['id']}] has no usage or rates.");
        }

        $usage = TokenUsage::fromApi($object['usage']);
        $cost = $this->calculator->providerCost($usage, Rates::fromApi($object['rates']));

        $generation->forceFill([
            'input_text_tokens' => $usage->inputText,
            'input_image_tokens' => $usage->inputImage,
            'output_tokens' => $usage->output,
            'reasoning_tokens' => $usage->reasoning,
            'provider_cost_nano' => $cost,
            'charge_nano' => $this->calculator->chargeFor($cost),
            'image_url' => $object['image_url'] ?? null,
            'image_url_expires_at' => $this->time($object['image_url_expires_at'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function recordFailure(Generation $generation, array $object): void
    {
        $generation->forceFill([
            'error_code' => $object['error']['code'] ?? 'unknown_error',
            'error_message' => $object['error']['message'] ?? 'The generation failed.',
            'provider_cost_nano' => 0,
            'charge_nano' => 0,
        ]);
    }

    private function time(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->utc();
    }
}
