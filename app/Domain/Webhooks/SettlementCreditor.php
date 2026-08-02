<?php

namespace App\Domain\Webhooks;

use App\Domain\Swap\SystemAccounts;
use App\Enums\LedgerDirection;
use App\Models\LedgerEntry;
use App\Models\SettlementWebhookEvent;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SettlementCreditor
{
    public function __construct(private readonly SystemAccounts $systemAccounts) {}

    public function credit(SettlementWebhookEvent $event): void
    {
        if (LedgerEntry::query()->where('reference', $event->provider_reference)->exists()) {
            return;
        }

        // Larastan doesn't resolve the method-based casts() array type here;
        // verified via tinker that $event->payload is always a real array.
        /** @var array<string, mixed> $payload */
        $payload = $event->payload;

        $walletId = $payload['wallet_id'] ?? null;
        $amountSubunits = $payload['amount_subunits'] ?? null;
        $currency = $payload['currency'] ?? null;

        if (! $walletId || ! $amountSubunits || ! $currency) {
            throw new RuntimeException("Settlement event {$event->provider_reference} is missing wallet_id/amount_subunits/currency.");
        }

        $wallet = Wallet::findOrFail($walletId);

        if (! $wallet instanceof Wallet) {
            throw new RuntimeException("Unexpected wallet lookup result for settlement {$event->provider_reference}.");
        }
        $settlementInbound = $this->systemAccounts->fxClearing($currency);
        $groupId = (string) Str::uuid();

        DB::transaction(function () use ($wallet, $settlementInbound, $amountSubunits, $currency, $groupId, $event) {
            LedgerEntry::insert([
                [
                    'id' => (string) Str::uuid(),
                    'transaction_group_id' => $groupId,
                    'wallet_id' => $settlementInbound->id,
                    'direction' => LedgerDirection::Debit->value,
                    'amount_subunits' => $amountSubunits,
                    'currency' => $currency,
                    'reference' => $event->provider_reference,
                    'description' => "Settlement {$event->provider_reference}",
                    'metadata' => null,
                    'created_at' => now(),
                ],
                [
                    'id' => (string) Str::uuid(),
                    'transaction_group_id' => $groupId,
                    'wallet_id' => $wallet->id,
                    'direction' => LedgerDirection::Credit->value,
                    'amount_subunits' => $amountSubunits,
                    'currency' => $currency,
                    'reference' => $event->provider_reference,
                    'description' => "Settlement {$event->provider_reference}",
                    'metadata' => null,
                    'created_at' => now(),
                ],
            ]);

            $event->update(['processed_at' => now()]);
        });
    }
}
