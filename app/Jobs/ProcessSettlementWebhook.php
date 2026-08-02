<?php

namespace App\Jobs;

use App\Domain\Webhooks\SettlementCreditor;
use App\Enums\WebhookStatus;
use App\Models\SettlementWebhookEvent;
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

    public function handle(SettlementCreditor $creditor): void
    {
        $event = SettlementWebhookEvent::findOrFail($this->webhookEventId);

        // Larastan doesn't resolve the method-based casts() enum type here;
        // verified via tinker that $event->status is always a real WebhookStatus.
        /** @var WebhookStatus $status */
        $status = $event->status;

        if ($status === WebhookStatus::Completed) {
            $creditor->credit($event);
        }
    }
}
