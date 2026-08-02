<?php

use App\Domain\Swap\SlippageCalculator;

beforeEach(function () {
    $this->calculator = new SlippageCalculator;
});

it('charges no fee at or under the 1,000,000 NGN threshold', function () {
    expect($this->calculator->feeSubunits(100_000_000))->toBe(0);
    expect($this->calculator->feeSubunits(50_000_000))->toBe(0);
});

it('charges the base tier fee for amounts just over the threshold', function () {
    // 100,000,001 kobo * 0.6% = 600,000.006 -> bankers-rounds to 600,000
    expect($this->calculator->feeSubunits(100_000_001))->toBe(600000);
});

it('charges exactly one tier for a swap exactly one tier over the threshold', function () {
    // 150,000,000 kobo (1.5M NGN) * 0.6% = 900,000
    expect($this->calculator->feeSubunits(150_000_000))->toBe(900000);
});

it('charges two tiers for a swap two tiers over the threshold', function () {
    // 200,000,000 kobo (2M NGN) * 0.7% = 1,400,000
    expect($this->calculator->feeSubunits(200_000_000))->toBe(1400000);
});
