<?php

namespace App\Domain\Swap;

use App\Models\Wallet;
use RuntimeException;

class SystemAccounts
{
    public const LABEL_FX_CLEARING = 'fx_clearing';

    public const LABEL_FEE_REVENUE = 'fee_revenue';

    public function fxClearing(string $currency): Wallet
    {
        return $this->find(self::LABEL_FX_CLEARING, $currency);
    }

    public function feeRevenue(string $currency): Wallet
    {
        return $this->find(self::LABEL_FEE_REVENUE, $currency);
    }

    private function find(string $label, string $currency): Wallet
    {
        $wallet = Wallet::query()->labeled($label, $currency)->first();

        if (! $wallet) {
            throw new RuntimeException("System wallet not seeded: {$label}/{$currency}. Run SystemAccountsSeeder.");
        }

        return $wallet;
    }
}
