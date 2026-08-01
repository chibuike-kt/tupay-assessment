<?php

namespace App\Domain\Security;

class ActionHasher
{
  /**
   * @param  array<string, mixed>  $payload
   */
  public function hash(int $userId, string $action, array $payload): string
  {
    $canonical = [
      'user_id' => $userId,
      'action' => $action,
      'payload' => $this->sortRecursively($payload),
    ];

    return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
  }

  /**
   * @param  array<string, mixed>  $value
   * @return array<string, mixed>
   */
  private function sortRecursively(array $value): array
  {
    foreach ($value as $key => $item) {
      if (is_array($item)) {
        $value[$key] = $this->sortRecursively($item);
      }
    }

    ksort($value);

    return $value;
  }
}
