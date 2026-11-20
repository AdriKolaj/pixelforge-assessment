<?php

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('adds purchased credits to the balance and the ledger', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/credits', ['amount' => 10])->assertRedirect();

    expect($user->fresh()->balance_nano)->toBe(10_000_000_000);

    $transaction = CreditTransaction::sole();
    expect($transaction->type)->toBe(CreditTransaction::TYPE_PURCHASE)
        ->and($transaction->amount_nano)->toBe(10_000_000_000);
});

it('only sells the listed packs', function (mixed $amount) {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/credits', ['amount' => $amount])->assertSessionHasErrors('amount');

    expect($user->fresh()->balance_nano)->toBe(0);
})->with([7, -5, 'ten', 1_000_000]);

it('shows the balance, held credits and activity', function () {
    $user = User::factory()->create(['balance_nano' => 2_000_000_000]);
    Generation::factory()->for($user)->create(['max_charge_nano' => 300_000_000]);
    $user->creditTransactions()->create(['type' => 'purchase', 'amount_nano' => 2_000_000_000, 'description' => 'Bought $2.00 of credits']);

    $this->actingAs($user)
        ->get('/credits')
        ->assertInertia(fn (Assert $page) => $page
            ->component('credits/Index')
            ->where('credits.balance_nano', 2_000_000_000)
            ->where('credits.available_nano', 1_700_000_000)
            ->has('transactions.data', 1));
});

it('keeps the balance equal to the sum of the ledger', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/credits', ['amount' => 5]);

    $generation = Generation::factory()->for($user)->queued('gen_ledger')->create();
    postWebhook(webhookEvent(pixelforgeGeneration($generation, 'succeeded')));
    postWebhook(webhookEvent(pixelforgeGeneration($generation, 'succeeded')));

    expect($user->fresh()->balance_nano)
        ->toBe((int) $user->creditTransactions()->sum('amount_nano'))
        ->toBe(5_000_000_000 - 70_700_000);
});
