<?php

namespace App\Domain\Security;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

class TotpVerifier
{
  public function __construct(private readonly Google2FA $google2fa) {}

  public function verify(User $user, string $code): bool
  {
    $secret = $user->totp_secret;

    if ($secret === null || $user->totp_confirmed_at === null) {
      return false;
    }

    return $this->google2fa->verifyKey($secret, $code) === true;
  }
}
