<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\ProfitReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(Request $request, ProfitReport $report): Response
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        $end = CarbonImmutable::parse($validated['end'] ?? now('UTC')->toDateString(), 'UTC');
        $start = CarbonImmutable::parse($validated['start'] ?? $end->subDays(13)->toDateString(), 'UTC');

        // Same cap as Pixelforge's usage endpoint, so the two always line up.
        if ($start->diffInDays($end) + 1 > ProfitReport::MAX_DAYS) {
            $start = $end->subDays(ProfitReport::MAX_DAYS - 1);
        }

        return Inertia::render('admin/Report', [
            'report' => $report->build($start, $end),
        ]);
    }
}
