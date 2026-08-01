<?php

namespace App\Domain\Swap;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpRateClient implements RateClient
{
    public function fetch(string $base, string $quote): string
    {
        $response = Http::timeout(3)
            ->retry(2, 100)
            ->get(config('services.rate_provider.url'), [
                'base' => $base,
                'quote' => $quote,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Rate provider request failed for {$base}/{$quote}: HTTP {$response->status()}");
        }

        $rate = (string) $response->json('rate');

        if ($rate === '' || ! is_numeric($rate)) {
            throw new RuntimeException("Rate provider returned a non-numeric rate for {$base}/{$quote}.");
        }

        return $rate;
    }
}
