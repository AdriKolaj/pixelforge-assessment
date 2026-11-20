<?php

namespace App\Generations;

use App\Billing\CreditLedger;
use App\Billing\InsufficientCredits;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pricing\CostCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StartGeneration
{
    public function __construct(
        private readonly PixelforgeClient $pixelforge,
        private readonly CostCalculator $calculator,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * Saves the generation and queues it for Pixelforge. It is stored before
     * anything is sent, so a webhook that beats the create response still has
     * a record to land on.
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images: list<string>}  $input
     *
     * @throws InsufficientCredits
     */
    public function __invoke(User $user, array $input): Generation
    {
        $quote = $this->calculator->quote(
            $this->pixelforge->pricing(),
            $input['prompt'],
            $input['size'],
            $input['quality'],
            count($input['reference_images']),
        );

        $generation = DB::transaction(function () use ($user, $input, $quote) {
            // Lock the user so two quick submits can't both spend the same credits.
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            $available = $this->ledger->available($user);

            // We hold the worst case, not the estimate: the real cost is only
            // known at the end, and it must always be covered.
            if ($available < $quote->maxChargeNano) {
                throw new InsufficientCredits($quote->maxChargeNano, $available);
            }

            return $user->generations()->create([
                'idempotency_key' => (string) Str::uuid(),
                'status' => GenerationStatus::Pending,
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $input['reference_images'],
                'estimated_charge_nano' => $quote->estimatedChargeNano,
                'max_charge_nano' => $quote->maxChargeNano,
            ]);
        });

        SubmitGeneration::dispatch($generation);

        return $generation;
    }
}
