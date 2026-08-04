<?php

namespace App\Domain\Swap;

use App\Domain\Swap\Exceptions\InsufficientBalanceException;
use App\Domain\Swap\Exceptions\InvalidWalletPairException;
use App\Domain\Swap\Exceptions\SwapLockContentionException;
use App\Enums\LedgerDirection;
use App\Enums\SwapStatus;
use App\Models\LedgerEntry;
use App\Models\Swap;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SwapService
{
    public function __construct(
        private readonly SwapLock $lock,
        private readonly RateService $rates,
        private readonly SlippageCalculator $slippage,
        private readonly SystemAccounts $systemAccounts,
    ) {}

    public function execute(User $user, Wallet $source, Wallet $destination, int $sourceAmountSubunits): Swap
    {
        if ($source->currency === $destination->currency) {
            throw new InvalidWalletPairException('Source and destination wallets must be in different currencies.');
        }

        if ($source->user_id !== $user->id || $destination->user_id !== $user->id) {
            throw new InvalidWalletPairException('Both wallets must belong to the authenticated user.');
        }

        try {
            $release = $this->lock->acquire($user->id, $source->id, $destination->id);
        } catch (RuntimeException) {
            throw new SwapLockContentionException;
        }

        try {
            $alreadyInTransaction = DB::transactionLevel() > 0;

            DB::beginTransaction();

            // Postgres requires SET TRANSACTION ISOLATION LEVEL to be the
            // first statement of the outer transaction block. In production
            // this method always starts that outer transaction, so this
            // always applies. Under RefreshDatabase in tests, the test
            // itself already opened one, so this would be mid-transaction
            // and Postgres rejects it — skipping it there is safe:
            // correctness here comes primarily from the Redis lock plus the
            // row-level FOR UPDATE lock acquired below, both of which force
            // serialization regardless of isolation level.
            if (! $alreadyInTransaction) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            try {
                $swap = $this->applySwap($user, $source, $destination, $sourceAmountSubunits);
                DB::commit();

                return $swap;
            } catch (Throwable $e) {
                DB::rollBack();

                throw $e;
            }
        } finally {
            $release();
        }
    }

    private function applySwap(User $user, Wallet $source, Wallet $destination, int $sourceAmountSubunits): Swap
    {
        $balance = $source->lockedBalance();

        if ($balance < $sourceAmountSubunits) {
            throw new InsufficientBalanceException;
        }

        $feeSubunits = $source->currency === 'NGN'
          ? $this->slippage->feeSubunits($sourceAmountSubunits)
          : 0;

        $netAfterFee = $sourceAmountSubunits - $feeSubunits;

        $rate = $this->rates->getRate($source->currency, $destination->currency);
        if (! is_numeric($rate)) {
            throw new RuntimeException("Rate provider returned a non-numeric rate: {$rate}");
        }
        $destinationAmountRaw = bcmul((string) $netAfterFee, $rate, 10);
        $destinationAmountSubunits = (int) BankersRounder::toInteger($destinationAmountRaw);

        $swap = Swap::create([
            'user_id' => $user->id,
            'source_wallet_id' => $source->id,
            'destination_wallet_id' => $destination->id,
            'source_amount_subunits' => $sourceAmountSubunits,
            'destination_amount_subunits' => $destinationAmountSubunits,
            'fee_subunits' => $feeSubunits,
            'rate_applied' => $rate,
            'status' => SwapStatus::Pending,
        ]);

        $this->postLedgerEntries($swap, $source, $destination, $feeSubunits, $netAfterFee, $destinationAmountSubunits);

        $swap->update(['status' => SwapStatus::Completed]);

        return $swap;
    }

    private function postLedgerEntries(
        Swap $swap,
        Wallet $source,
        Wallet $destination,
        int $feeSubunits,
        int $netAfterFee,
        int $destinationAmountSubunits,
    ): void {
        $fxClearingSource = $this->systemAccounts->fxClearing($source->currency);
        $fxClearingDestination = $this->systemAccounts->fxClearing($destination->currency);

        $entries = [
            $this->entry($swap, $source, LedgerDirection::Debit, $swap->source_amount_subunits),
            $this->entry($swap, $fxClearingSource, LedgerDirection::Credit, $netAfterFee),
        ];

        if ($feeSubunits > 0) {
            $entries[] = $this->entry($swap, $this->systemAccounts->feeRevenue($source->currency), LedgerDirection::Credit, $feeSubunits);
        }

        $entries[] = $this->entry($swap, $fxClearingDestination, LedgerDirection::Debit, $destinationAmountSubunits);
        $entries[] = $this->entry($swap, $destination, LedgerDirection::Credit, $destinationAmountSubunits);

        LedgerEntry::insert($entries);
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(Swap $swap, Wallet $wallet, LedgerDirection $direction, int $amountSubunits): array
    {
        return [
            'id' => (string) Str::uuid(),
            'transaction_group_id' => $swap->transaction_group_id,
            'wallet_id' => $wallet->id,
            'direction' => $direction->value,
            'amount_subunits' => $amountSubunits,
            'currency' => $wallet->currency,
            'reference' => $swap->id,
            'description' => "Swap {$swap->id}",
            'metadata' => null,
            'created_at' => now(),
        ];
    }
}
