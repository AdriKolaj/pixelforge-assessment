<?php

namespace App\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Support\Usd;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Spend, provider cost and profit per UTC day and per user, next to what
 * Pixelforge's usage report says it billed for the same days.
 *
 * Generations are counted on the day they completed, which is also how
 * Pixelforge bills them.
 */
class ProfitReport
{
    public const MAX_DAYS = 92;

    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->rows($start, $end);
        $usage = $this->providerUsage($start, $end);
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique())->pluck('email', 'id');

        $dates = [];
        for ($day = $end; $day->gte($start); $day = $day->subDay()) {
            $dates[] = $day->toDateString();
        }

        $days = collect($dates)
            ->map(function (string $date) use ($rows, $usage, $users) {
                $dayRows = $rows->where('day', $date)->values();

                $cost = (int) $dayRows->sum('provider_cost_nano');
                $charged = (int) $dayRows->sum('charge_nano');
                $pixelforgeCost = $usage['days'][$date] ?? null;

                return [
                    'date' => $date,
                    'generations' => (int) $dayRows->sum('generations'),
                    'charged_nano' => $charged,
                    'provider_cost_nano' => $cost,
                    'profit_nano' => $charged - $cost,
                    'pixelforge_cost_nano' => $pixelforgeCost,
                    'matches_pixelforge' => $pixelforgeCost === null ? null : $pixelforgeCost === $cost,
                    'users' => $dayRows->map(fn (array $row) => [
                        'user_id' => $row['user_id'],
                        'email' => $users[$row['user_id']] ?? 'deleted user',
                        'generations' => $row['generations'],
                        'charged_nano' => $row['charge_nano'],
                        'provider_cost_nano' => $row['provider_cost_nano'],
                        'profit_nano' => $row['charge_nano'] - $row['provider_cost_nano'],
                    ])->sortByDesc('charged_nano')->values()->all(),
                ];
            });

        $totalCost = (int) $days->sum('provider_cost_nano');
        $totalCharged = (int) $days->sum('charged_nano');

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days->all(),
            'totals' => [
                'generations' => (int) $days->sum('generations'),
                'charged_nano' => $totalCharged,
                'provider_cost_nano' => $totalCost,
                'profit_nano' => $totalCharged - $totalCost,
                'pixelforge_cost_nano' => $usage['total'],
                'matches_pixelforge' => $usage['total'] === null ? null : $usage['total'] === $totalCost,
            ],
            'pixelforge_error' => $usage['error'],
        ];
    }

    /**
     * @return Collection<int, array{day: string, user_id: int, generations: int, charge_nano: int, provider_cost_nano: int}>
     */
    private function rows(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        // DATE() works the same on SQLite, MySQL and Postgres.
        return Generation::query()
            ->toBase()
            ->selectRaw('DATE(completed_at) as day, user_id, COUNT(*) as generations, SUM(charge_nano) as charge_nano, SUM(provider_cost_nano) as provider_cost_nano')
            ->where('status', GenerationStatus::Succeeded)
            ->where('completed_at', '>=', $start->startOfDay())
            ->where('completed_at', '<', $end->addDay()->startOfDay())
            ->groupByRaw('DATE(completed_at), user_id')
            ->get()
            ->map(fn (object $row) => [
                // Drivers disagree on how a DATE comes back; keep the Y-m-d part.
                'day' => substr((string) $row->day, 0, 10),
                'user_id' => (int) $row->user_id,
                'generations' => (int) $row->generations,
                // SUM() can come back as a string (Postgres numeric), never as a float here.
                'charge_nano' => (int) $row->charge_nano,
                'provider_cost_nano' => (int) $row->provider_cost_nano,
            ]);
    }

    /**
     * @return array{days: array<string, int>, total: int|null, error: string|null}
     */
    private function providerUsage(CarbonImmutable $start, CarbonImmutable $end): array
    {
        try {
            // Short cache: refreshing the page shouldn't burn the rate limit.
            $usage = Cache::remember(
                "pixelforge.usage.{$start->toDateString()}.{$end->toDateString()}",
                now()->addMinute(),
                fn () => $this->pixelforge->usage($start->toDateString(), $end->toDateString()),
            );
        } catch (PixelforgeException $e) {
            return ['days' => [], 'total' => null, 'error' => $e->getMessage()];
        }

        $days = [];
        foreach ($usage['data'] as $day) {
            $days[$day['date']] = Usd::fromDollars($day['cost_usd']);
        }

        return [
            'days' => $days,
            'total' => Usd::fromDollars($usage['total']['cost_usd']),
            'error' => null,
        ];
    }
}
