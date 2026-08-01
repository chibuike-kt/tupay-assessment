<?php

namespace App\Enums;

enum LedgerDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
