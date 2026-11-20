<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function usageResponse(array $days): array
{
    $data = collect($days)->map(fn ($cost, $date) => [
        'date' => $date,
        'generations' => 1,
        'input_text_tokens' => 0,
        'input_image_tokens' => 0,
        'output_tokens' => 0,
        'reasoning_tokens' => 0,
        'cost_usd' => $cost,
    ])->values()->all();

    return [
        'object' => 'usage',
        'data' => $data,
        'total' => ['cost_usd' => number_format(array_sum(array_map('floatval', $days)), 9, '.', '')],
    ];
}

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->travelTo('2026-11-20 12:00:00');
});

it('is only for admins', function () {
    $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
});

it('groups spend, cost and profit by completion day and user', function () {
    $alice = User::factory()->create(['email' => 'alice@example.com']);
    $bob = User::factory()->create(['email' => 'bob@example.com']);

    Generation::factory()->for($alice)->succeeded(56_560_000, 70_700_000, '2026-11-19 23:59:59')->create();
    Generation::factory()->for($alice)->succeeded(1_000, 1_250, '2026-11-19 08:00:00')->create();
    Generation::factory()->for($bob)->succeeded(305_510_000, 381_887_500, '2026-11-20 00:00:00')->create();
    // Failed and unfinished generations cost nothing and don't show up.
    Generation::factory()->for($bob)->create(['status' => 'failed', 'charge_nano' => 0, 'completed_at' => '2026-11-20 01:00:00']);
    Generation::factory()->for($bob)->queued()->create();

    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usageResponse([
        '2026-11-19' => '0.056561000',
        '2026-11-20' => '0.305510000',
    ]))]);

    $this->actingAs($this->admin)
        ->get('/admin/report?start=2026-11-19&end=2026-11-20')
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('report.days.0.date', '2026-11-20')
            ->where('report.days.0.charged_nano', 381_887_500)
            ->where('report.days.0.provider_cost_nano', 305_510_000)
            ->where('report.days.0.profit_nano', 76_377_500)
            ->where('report.days.0.matches_pixelforge', true)
            ->where('report.days.1.date', '2026-11-19')
            ->where('report.days.1.generations', 2)
            ->where('report.days.1.provider_cost_nano', 56_561_000)
            ->where('report.days.1.users.0.email', 'alice@example.com')
            ->where('report.days.1.matches_pixelforge', true)
            ->where('report.totals.provider_cost_nano', 362_071_000)
            ->where('report.totals.matches_pixelforge', true));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'start_date=2026-11-19')
        && str_contains($request->url(), 'end_date=2026-11-20'));
});

it('flags days that do not match Pixelforge', function () {
    Generation::factory()->succeeded(1_000, 1_250, '2026-11-20 10:00:00')->create();
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(usageResponse(['2026-11-20' => '0.000002000']))]);

    $this->actingAs($this->admin)
        ->get('/admin/report?start=2026-11-20&end=2026-11-20')
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.days.0.matches_pixelforge', false)
            ->where('report.totals.matches_pixelforge', false));
});

it('still renders when Pixelforge is unreachable', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'Busy']], 503)]);

    $this->actingAs($this->admin)
        ->get('/admin/report')
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.pixelforge_error', 'Busy')
            ->where('report.totals.pixelforge_cost_nano', null)
            ->has('report.days', 14));
});

it('caps the range at 92 days like the usage endpoint', function () {
    Http::fake(['*' => Http::response(usageResponse([]))]);

    $this->actingAs($this->admin)
        ->get('/admin/report?start=2026-01-01&end=2026-11-20')
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.start', '2026-08-21')
            ->has('report.days', 92));
});

it('lets an operator grant admin access', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('app:make-admin', ['email' => 'ops@example.com'])->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeTrue();
});
