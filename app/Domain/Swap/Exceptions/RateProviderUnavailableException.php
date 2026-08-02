<?php

namespace App\Domain\Swap\Exceptions;

use Exception;

class RateProviderUnavailableException extends Exception
{
    public function __construct(string $pair)
    {
        parent::__construct("Exchange rate temporarily unavailable for {$pair}. Please try again shortly.");
    }
}
