<?php

namespace App\Enums;

enum SwapStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
}
