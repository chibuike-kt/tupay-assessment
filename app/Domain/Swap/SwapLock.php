<?php

namespace App\Domain\Swap;

use Illuminate\Support\Facades\Redis;
use RuntimeException;

class SwapLock
{
    private const TTL_SECONDS = 10;

    public function acquire(int $userId, int $walletIdA, int $walletIdB): callable
    {
        $keys = $this->lockKeys($userId, $walletIdA, $walletIdB);
        $tokens = [];

        foreach ($keys as $key) {
            $token = bin2hex(random_bytes(16));
            $acquired = Redis::command('set', [$key, $token, 'NX', 'EX', self::TTL_SECONDS]);

            if (! $acquired) {
                $this->release($tokens);

                throw new RuntimeException("Could not acquire swap lock for {$key}: contended.");
            }

            $tokens[$key] = $token;
        }

        return fn () => $this->release($tokens);
    }

    /**
     * @return array<int, string>
     */
    private function lockKeys(int $userId, int $walletIdA, int $walletIdB): array
    {
        $sortedWalletIds = [$walletIdA, $walletIdB];
        sort($sortedWalletIds);

        return [
            "lock:swap:user:{$userId}",
            "lock:swap:wallet:{$sortedWalletIds[0]}",
            "lock:swap:wallet:{$sortedWalletIds[1]}",
        ];
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function release(array $tokens): void
    {
        foreach ($tokens as $key => $token) {
            Redis::command('eval', [
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                1,
                $key,
                $token,
            ]);
        }
    }
}
