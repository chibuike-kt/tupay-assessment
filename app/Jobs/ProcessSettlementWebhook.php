<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSettlementWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 60, 300];

    public function __construct(private readonly int $webhookEventId) {}

    public function handle(): void
    {
        // Ledger crediting logic comes next — this is intentionally a stub
        // for now so we can verify the processor's state machine in
        // isolation first, same incremental approach as the rest of this session.
    }
}
