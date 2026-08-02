<?php

namespace App\Domain\Webhooks;

use App\Enums\WebhookStatus;
use App\Jobs\ProcessSettlementWebhook;
use App\Models\SettlementWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class SettlementWebhookProcessor
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingest(string $providerReference, WebhookStatus $incomingStatus, array $payload): void
    {
        $dedupeKey = "webhook:dedupe:{$providerReference}:{$incomingStatus->value}";
        $firstSeen = Redis::command('set', [$dedupeKey, '1', 'NX', 'EX', 300]);

        if (! $firstSeen) {
            return;
        }

        DB::transaction(function () use ($providerReference, $incomingStatus, $payload) {
            $event = SettlementWebhookEvent::query()
                ->where('provider_reference', $providerReference)
                ->lockForUpdate()
                ->first();

            if (! $event) {
                $event = SettlementWebhookEvent::create([
                    'provider_reference' => $providerReference,
                    'status' => $incomingStatus,
                    'payload' => $payload,
                    'received_at' => now(),
                ]);

                ProcessSettlementWebhook::dispatch($event->id);

                return;
            }

            $currentStatus = $event->status;

            // Larastan doesn't resolve the method-based casts() enum type for
            // this property; verified via tinker that $event->status is
            // always a real WebhookStatus instance.
            // @phpstan-ignore method.nonObject
            if (! $currentStatus->canTransitionTo($incomingStatus)) {
                return;
            }

            $event->update([
                'status' => $incomingStatus,
                'payload' => $payload,
                'received_at' => now(),
            ]);

            ProcessSettlementWebhook::dispatch($event->id);
        });
    }
}
