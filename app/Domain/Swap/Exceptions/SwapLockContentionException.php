<?php

namespace App\Domain\Swap\Exceptions;

use Exception;

class SwapLockContentionException extends Exception
{
    public function __construct()
    {
        parent::__construct('Another swap for this user or wallet is already in progress.');
    }
}
