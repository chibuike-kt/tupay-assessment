<?php

namespace App\Domain\Security;

use App\Domain\Security\Exceptions\InvalidElevatedActionTokenException;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ElevatedActionTokenService
{
  private const REDIS_PREFIX = 'eat:';

  public function __construct(private readonly ActionHasher $hasher) {}

  /**
   * @param  array<string, mixed>  $actionPayload
   */
  public function issue(User $user, string $action, array $actionPayload): string
  {
    $ttl = (int) config('security.eat.ttl_seconds');
    $nonce = Str::random(32);
    $actionHash = $this->hasher->hash($user->id, $action, $actionPayload);

    Redis::setex(self::REDIS_PREFIX . $nonce, $ttl, $actionHash);

    $envelope = [
      'nonce' => $nonce,
      'user_id' => $user->id,
      'exp' => now()->addSeconds($ttl)->getTimestamp(),
    ];

    $encoded = $this->base64UrlEncode(json_encode($envelope, JSON_THROW_ON_ERROR));
    $signature = $this->sign($encoded);

    return "{$encoded}.{$signature}";
  }

  /**
   * @param  array<string, mixed>  $actionPayload
   */
  public function consume(string $rawToken, User $user, string $action, array $actionPayload): void
  {
    [$encoded, $signature] = $this->splitToken($rawToken);

    if (! hash_equals($this->sign($encoded), $signature)) {
      throw InvalidElevatedActionTokenException::badSignature();
    }

    $envelope = json_decode($this->base64UrlDecode($encoded), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($envelope) || ! isset($envelope['nonce'], $envelope['user_id'], $envelope['exp'])) {
      throw InvalidElevatedActionTokenException::malformed();
    }

    if ((int) $envelope['exp'] < now()->getTimestamp()) {
      throw InvalidElevatedActionTokenException::expired();
    }

    if ((int) $envelope['user_id'] !== $user->id) {
      throw InvalidElevatedActionTokenException::actionMismatch();
    }

    $storedHash = Redis::getdel(self::REDIS_PREFIX . $envelope['nonce']);

    if (! is_string($storedHash) || $storedHash === '') {
      throw InvalidElevatedActionTokenException::alreadyConsumedOrUnknown();
    }

    $expectedHash = $this->hasher->hash($user->id, $action, $actionPayload);

    if (! hash_equals($expectedHash, $storedHash)) {
      throw InvalidElevatedActionTokenException::actionMismatch();
    }
  }

  /**
   * @return array{0: string, 1: string}
   */
  private function splitToken(string $rawToken): array
  {
    $parts = explode('.', $rawToken);

    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
      throw InvalidElevatedActionTokenException::malformed();
    }

    return [$parts[0], $parts[1]];
  }

  private function sign(string $encoded): string
  {
    return hash_hmac('sha256', $encoded, (string) config('security.eat.signing_key'));
  }

  private function base64UrlEncode(string $value): string
  {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }

  private function base64UrlDecode(string $value): string
  {
    return base64_decode(strtr($value, '-_', '+/'));
  }
}
