<?php

namespace App\Http\Controllers;

use App\Billing\CreditLedger;
use App\Http\Requests\QuoteRequest;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Pricing\CostCalculator;
use Illuminate\Http\JsonResponse;

class QuoteController extends Controller
{
    public function __invoke(
        QuoteRequest $request,
        PixelforgeClient $pixelforge,
        CostCalculator $calculator,
        CreditLedger $ledger,
    ): JsonResponse {
        try {
            $pricing = $pixelforge->pricing();
        } catch (PixelforgeException) {
            return response()->json(['message' => 'Prices are unavailable right now.'], 503);
        }

        $quote = $calculator->quote(
            $pricing,
            (string) $request->validated('prompt'),
            $request->validated('size'),
            $request->validated('quality'),
            (int) $request->validated('reference_image_count'),
        );

        return response()->json([
            'estimated_charge_nano' => $quote->estimatedChargeNano,
            'max_charge_nano' => $quote->maxChargeNano,
            'available_nano' => $ledger->available($request->user()),
            'tokens' => [
                'input_text' => ['estimated' => $quote->estimatedUsage->inputText, 'max' => $quote->maxUsage->inputText],
                'input_image' => $quote->estimatedUsage->inputImage,
                'output' => $quote->estimatedUsage->output,
                'reasoning' => ['estimated' => $quote->estimatedUsage->reasoning, 'max' => $quote->maxUsage->reasoning],
            ],
        ]);
    }
}
