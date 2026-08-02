<?php

namespace App\Domain\Swap;

use App\Jobs\RefreshExchangeRate;
use Illuminate\Support\Facades\Redis;

class RateService
{
    public function __construct(private readonly RateClient $client) {}

    public function getRate(string $base, string $quote): string
    {
        $key = "rate:{$base}_{$quote}";
        $cached = Redis::hgetall($key);
        $now = now()->getTimestamp();

        $freshTtl = (int) config('services.rate_provider.cache_ttl_seconds');
        $staleTtl = (int) config('services.rate_provider.stale_ttl_seconds');

        if (! empty($cached)) {
            $age = $now - (int) $cached['fetched_at'];

            if ($age <= $freshTtl) {
                return $cached['value'];
            }

            if ($age <= $freshTtl + $staleTtl) {
                RefreshExchangeRate::dispatch($base, $quote);

                return $cached['value'];
            }
        }

        return $this->fetchAndCache($base, $quote, $key);
    }

    private function fetchAndCache(string $base, string $quote, string $key): string
    {
        $rate = $this->client->fetch($base, $quote);

        Redis::hmset($key, ['value' => $rate, 'fetched_at' => now()->getTimestamp()]);

        return $rate;
    }
}
