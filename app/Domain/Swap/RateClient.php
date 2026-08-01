<?php

namespace App\Domain\Swap;

interface RateClient
{
    public function fetch(string $base, string $quote): string;
}
