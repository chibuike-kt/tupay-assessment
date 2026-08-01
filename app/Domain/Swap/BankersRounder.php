<?php

namespace App\Domain\Swap;

use InvalidArgumentException;

class BankersRounder
{
    public static function toInteger(string $decimal, int $workingScale = 10): string
    {
        if (! is_numeric($decimal)) {
            throw new InvalidArgumentException("BankersRounder expects a numeric string, got: {$decimal}");
        }

        $decimal = bcadd($decimal, '0', $workingScale);

        $negative = str_starts_with($decimal, '-');
        if ($negative) {
            $decimal = substr($decimal, 1);
        }

        [$whole, $frac] = array_pad(explode('.', $decimal), 2, '0');
        $frac = str_pad($frac, $workingScale, '0');

        $firstFracDigit = $frac[0];
        $remainderIsZero = rtrim(substr($frac, 1), '0') === '';

        $roundUp = match (true) {
            $firstFracDigit > '5' => true,
            $firstFracDigit < '5' => false,
            ! $remainderIsZero => true,
            default => ((int) substr($whole, -1)) % 2 !== 0,
        };

        assert(is_numeric($whole));

        $result = $roundUp ? bcadd($whole, '1', 0) : $whole;

        return $negative && $result !== '0' ? "-{$result}" : $result;
    }
}
