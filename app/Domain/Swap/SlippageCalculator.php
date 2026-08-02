<?php

namespace App\Domain\Swap;

class SlippageCalculator
{
    private const THRESHOLD_SUBUNITS = 100_000_000; // 1,000,000 NGN in kobo

    private const TIER_SIZE_SUBUNITS = 50_000_000; // 500,000 NGN in kobo

    private const BASE_RATE = '0.005';

    private const PER_TIER_RATE = '0.001';

    public function feeSubunits(int $sourceAmountSubunits): int
    {
        if ($sourceAmountSubunits <= self::THRESHOLD_SUBUNITS) {
            return 0;
        }

        $excess = $sourceAmountSubunits - self::THRESHOLD_SUBUNITS;
        $tiers = (int) ceil($excess / self::TIER_SIZE_SUBUNITS);

        $feeRate = bcadd(self::BASE_RATE, bcmul((string) $tiers, self::PER_TIER_RATE, 10), 10);
        $feeRaw = bcmul((string) $sourceAmountSubunits, $feeRate, 10);

        return (int) BankersRounder::toInteger($feeRaw);
    }
}
