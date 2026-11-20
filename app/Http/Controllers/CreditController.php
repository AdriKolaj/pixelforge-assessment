<?php

namespace App\Http\Controllers;

use App\Billing\CreditLedger;
use App\Models\CreditTransaction;
use App\Support\Usd;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public const PACKS = [5, 10, 25];

    public function index(Request $request): Response
    {
        $transactions = $request->user()
            ->creditTransactions()
            ->with('generation:id,prompt,size,quality')
            ->latest('id')
            ->paginate(25)
            ->through(fn (CreditTransaction $transaction) => [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'amount_nano' => $transaction->amount_nano,
                'description' => $transaction->description,
                'generation' => $transaction->generation?->only(['id', 'prompt', 'size', 'quality']),
                'created_at' => $transaction->created_at->toIso8601String(),
            ]);

        return Inertia::render('credits/Index', [
            'transactions' => $transactions,
        ]);
    }

    /**
     * Fake checkout: the credits are simply added.
     */
    public function store(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', Rule::in(self::PACKS)],
        ]);

        $transaction = $ledger->purchase($request->user(), Usd::fromDollars($validated['amount']));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Added '.Usd::format($transaction->amount_nano).' of credits.']);

        return back();
    }
}
