<?php

namespace App\Domain\Security\Exceptions;

use Exception;

class InvalidElevatedActionTokenException extends Exception
{
    public static function malformed(): self
    {
        return new self('Elevated action token is malformed.');
    }

    public static function badSignature(): self
    {
        return new self('Elevated action token signature is invalid.');
    }

    public static function expired(): self
    {
        return new self('Elevated action token has expired.');
    }

    public static function alreadyConsumedOrUnknown(): self
    {
        return new self('Elevated action token has already been used or does not exist.');
    }

    public static function actionMismatch(): self
    {
        return new self('Elevated action token does not match the requested action.');
    }
}
