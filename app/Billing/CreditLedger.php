<?php

namespace App\Billing;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Usd;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes a user's balance. Every change is written to
 * credit_transactions as well, so the balance can always be explained.
 */
class CreditLedger
{
    public function purchase(User $user, int $amountNano): CreditTransaction
    {
        return DB::transaction(function () use ($user, $amountNano) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $user->increment('balance_nano', $amountNano);

            return $user->creditTransactions()->create([
                'type' => CreditTransaction::TYPE_PURCHASE,
                'amount_nano' => $amountNano,
                'description' => 'Bought '.Usd::format($amountNano).' of credits',
            ]);
        });
    }

    /**
     * Money still free to spend: the balance minus the worst-case price of
     * everything that hasn't finished yet. Call inside a transaction holding
     * the user's row lock if you act on the result.
     */
    public function available(User $user): int
    {
        $reserved = (int) $user->generations()->active()->sum('max_charge_nano');

        return $user->balance_nano - $reserved;
    }

    /**
     * Must run inside the transaction that marks the generation succeeded.
     */
    public function chargeForGeneration(Generation $generation): void
    {
        // The unique index on generation_id backs this up at database level.
        if (CreditTransaction::query()->where('generation_id', $generation->id)->exists()) {
            return;
        }

        $user = User::query()->lockForUpdate()->findOrFail($generation->user_id);
        $user->decrement('balance_nano', $generation->charge_nano);

        $user->creditTransactions()->create([
            'type' => CreditTransaction::TYPE_GENERATION,
            'amount_nano' => -$generation->charge_nano,
            'generation_id' => $generation->id,
            'description' => 'Image generation #'.$generation->id,
        ]);
    }
}
