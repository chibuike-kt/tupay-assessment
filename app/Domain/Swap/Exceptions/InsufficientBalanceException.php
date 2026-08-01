<?php

namespace App\Domain\Swap\Exceptions;

use Exception;

class InsufficientBalanceException extends Exception
{
    public function __construct()
    {
        parent::__construct('Source wallet does not have sufficient balance for this swap.');
    }
}
