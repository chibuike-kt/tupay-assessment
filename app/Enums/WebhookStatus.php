<?php

namespace App\Enums;

enum WebhookStatus: string
{
    case Initiated = 'initiated';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Initiated => in_array($next, [self::Processing, self::Completed, self::Failed], true),
            self::Processing => in_array($next, [self::Completed, self::Failed], true),
            self::Completed, self::Failed => false,
        };
    }
}
