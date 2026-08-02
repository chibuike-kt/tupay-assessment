<?php

use App\Domain\Swap\BankersRounder;

it('rounds down below the halfway point', function () {
    expect(BankersRounder::toInteger('10.49'))->toBe('10');
});

it('rounds up above the halfway point', function () {
    expect(BankersRounder::toInteger('10.51'))->toBe('11');
});

it('rounds exactly halfway to the nearest even digit', function () {
    expect(BankersRounder::toInteger('10.5'))->toBe('10');
    expect(BankersRounder::toInteger('11.5'))->toBe('12');
    expect(BankersRounder::toInteger('2.5'))->toBe('2');
    expect(BankersRounder::toInteger('3.5'))->toBe('4');
});

it('treats anything past the halfway point as rounding up, not exactly half', function () {
    expect(BankersRounder::toInteger('10.5000001'))->toBe('11');
});

it('applies half-to-even symmetrically to negative numbers', function () {
    expect(BankersRounder::toInteger('-2.5'))->toBe('-2');
    expect(BankersRounder::toInteger('-3.5'))->toBe('-4');
});

it('passes whole numbers through unchanged', function () {
    expect(BankersRounder::toInteger('42'))->toBe('42');
});

it('rejects non-numeric input rather than silently producing garbage', function () {
    expect(fn () => BankersRounder::toInteger('not-a-number'))
        ->toThrow(InvalidArgumentException::class);
});
