<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MockRateController extends Controller
{
    /**
     * Deliberately fake, deterministic-ish exchange rates for local dev and
     * CI, standing in for a real market-data provider. A small random walk
     * around a fixed midpoint keeps SWR cache-refresh behavior observable
     * (the rate actually changes between fetches) without ever producing
     * an unusable or wildly unrealistic number.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $base = strtoupper((string) $request->query('base', 'NGN'));
        $quote = strtoupper((string) $request->query('quote', 'CNY'));

        $midpoints = [
            'NGN_CNY' => 0.0048,
            'CNY_NGN' => 208.33,
        ];

        $midpoint = $midpoints["{$base}_{$quote}"] ?? null;

        if ($midpoint === null) {
            return response()->json(['message' => "No mock rate configured for {$base}/{$quote}."], 404);
        }

        $jitter = 1 + (mt_rand(-50, 50) / 10000); // ±0.5%
        $rate = $midpoint * $jitter;

        return response()->json(['rate' => number_format($rate, 8, '.', '')]);
    }
}
