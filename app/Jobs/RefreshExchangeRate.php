<?php

namespace App\Jobs;

use App\Domain\Swap\RateClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RefreshExchangeRate implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 30;

    public function __construct(private readonly string $base, private readonly string $quote) {}

    public function uniqueId(): string
    {
        return "rate-refresh:{$this->base}:{$this->quote}";
    }

    public function handle(RateClient $client): void
    {
        try {
            $rate = $client->fetch($this->base, $this->quote);
            $key = "rate:{$this->base}_{$this->quote}";
            Redis::hmset($key, ['value' => $rate, 'fetched_at' => now()->getTimestamp()]);
        } catch (\Throwable $e) {
            Log::warning('Exchange rate refresh failed', [
                'pair' => "{$this->base}/{$this->quote}",
                'error' => $e->getMessage(),
            ]);
        }
    }
}
