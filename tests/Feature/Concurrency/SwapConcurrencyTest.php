<?php

use App\Models\LedgerEntry;
use App\Models\Swap;
use App\Models\User;
use Database\Seeders\SystemAccountsSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

afterEach(function () {
    if (isset($this->user)) {
        $walletIds = $this->user->wallets()->pluck('id');

        LedgerEntry::whereIn('wallet_id', $walletIds)->delete();
        Swap::where('user_id', $this->user->id)->delete();
        $this->user->wallets()->delete();
        $this->user->delete();
    }
});

it('allows exactly one of 10 concurrent swap requests to succeed, rejects the other 9, and keeps the ledger exact', function () {
    Artisan::call('db:seed', [
        '--class' => SystemAccountsSeeder::class,
        '--force' => true,
    ]);

    $this->user = User::create([
        'name' => 'Concurrency Stress Test User',
        'email' => 'concurrency-stress-'.uniqid().'@tupay.dev',
        'password' => 'password',
    ]);

    $source = $this->user->wallets()->create(['currency' => 'NGN']);
    $destination = $this->user->wallets()->create(['currency' => 'CNY']);

    $swapAmount = 50000;
    $source->ledgerEntries()->create([
        'transaction_group_id' => Str::uuid(),
        'direction' => 'credit',
        'amount_subunits' => $swapAmount,
        'currency' => 'NGN',
        'description' => 'Concurrency stress test funding',
    ]);

    $token = $this->user->createToken('concurrency-stress')->plainTextToken;

    $client = new Client(['base_uri' => 'http://127.0.0.1:8000', 'http_errors' => false]);

    $totp = new Google2FA;
    $secret = $totp->generateSecretKey();
    $this->user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();
    $code = $totp->getCurrentOtp($secret);

    $eats = [];
    $challengeStatuses = [];
    for ($i = 0; $i < 10; $i++) {
        $challengeResponse = $client->post('/api/2fa/challenge', [
            'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
            'json' => [
                'totp_code' => $code,
                'action' => 'swap',
                'action_payload' => [
                    'source_wallet_id' => $source->id,
                    'destination_wallet_id' => $destination->id,
                    'amount_subunits' => $swapAmount,
                ],
            ],
        ]);

        $challengeStatuses[] = $challengeResponse->getStatusCode();
        $body = json_decode((string) $challengeResponse->getBody(), true);
        $eats[] = $body['elevated_action_token'] ?? null;
    }

    file_put_contents(
        base_path('concurrency-debug.json'),
        json_encode([
            'challenge_statuses' => $challengeStatuses,
            'challenge_eats' => $eats,
        ], JSON_PRETTY_PRINT)
    );

    expect($eats)->each->not->toBeNull();

    $promises = [];
    foreach ($eats as $eat) {
        $promises[] = $client->postAsync('/api/swap', [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'X-Elevated-Action-Token' => $eat,
                'Accept' => 'application/json',
            ],
            'json' => [
                'source_wallet_id' => $source->id,
                'destination_wallet_id' => $destination->id,
                'amount_subunits' => $swapAmount,
            ],
        ]);
    }

    $responses = Utils::settle($promises)->wait();

    $statusCodes = collect($responses)->map(function ($result) {
        return $result['state'] === 'fulfilled' ? $result['value']->getStatusCode() : null;
    });

    file_put_contents(
        base_path('concurrency-debug.json'),
        json_encode([
            'challenge_statuses' => $challengeStatuses,
            'status_codes' => $statusCodes->toArray(),
            'bodies' => collect($responses)->map(function ($result) {
                return $result['state'] === 'fulfilled'
                    ? (string) $result['value']->getBody()
                    : ($result['reason']->getMessage() ?? 'unknown rejection');
            })->toArray(),
        ], JSON_PRETTY_PRINT)
    );

    $successCount = $statusCodes->filter(fn ($code) => $code === 201)->count();
    $rejectedCount = $statusCodes->filter(fn ($code) => in_array($code, [409, 422], true))->count();

    expect($successCount)->toBe(1);
    expect($rejectedCount)->toBe(9);

    $finalSourceBalance = $source->fresh()->balance();
    $finalDestBalance = $destination->fresh()->balance();

    expect($finalSourceBalance)->toBe(0);
    expect($finalDestBalance)->toBeGreaterThan(0);
});
