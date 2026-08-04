<?php

use App\Models\SettlementWebhookEvent;
use App\Models\User;
use Database\Seeders\SystemAccountsSeeder;

function signedWebhookBody(array $payload): array
{
    $body = json_encode($payload);
    $secret = config('security.settlement_webhook.secret');
    $signature = hash_hmac('sha256', $body, $secret);

    return [$body, $signature];
}

beforeEach(function () {
    $this->seed(SystemAccountsSeeder::class);
    $this->user = User::factory()->create();
    $this->wallet = $this->user->wallets()->create(['currency' => 'NGN']);
});

it('accepts a validly signed completed settlement and credits the wallet', function () {
    [$body, $signature] = signedWebhookBody([
        'provider_reference' => 'feature-test-001',
        'status' => 'completed',
        'wallet_id' => $this->wallet->id,
        'amount_subunits' => 25000,
        'currency' => 'NGN',
    ]);

    $response = $this->postJson('/api/webhooks/settlement', json_decode($body, true), [
        'X-Settlement-Signature' => $signature,
    ]);

    $response->assertOk();
    $response->assertJson(['received' => true]);

    expect($this->wallet->fresh()->balance())->toBe(25000);
});

it('rejects a tampered signature', function () {
    $payload = [
        'provider_reference' => 'feature-test-002',
        'status' => 'completed',
        'wallet_id' => $this->wallet->id,
        'amount_subunits' => 25000,
        'currency' => 'NGN',
    ];

    $response = $this->postJson('/api/webhooks/settlement', $payload, [
        'X-Settlement-Signature' => str_repeat('0', 64),
    ]);

    $response->assertUnauthorized();
    expect($this->wallet->fresh()->balance())->toBe(0);
});

it('rejects a missing signature', function () {
    $response = $this->postJson('/api/webhooks/settlement', [
        'provider_reference' => 'feature-test-003',
        'status' => 'completed',
        'wallet_id' => $this->wallet->id,
        'amount_subunits' => 25000,
        'currency' => 'NGN',
    ]);

    $response->assertUnauthorized();
});

it('does not double-credit on an exact replay of the same delivery', function () {
    [$body, $signature] = signedWebhookBody([
        'provider_reference' => 'feature-test-004',
        'status' => 'completed',
        'wallet_id' => $this->wallet->id,
        'amount_subunits' => 25000,
        'currency' => 'NGN',
    ]);

    $headers = ['X-Settlement-Signature' => $signature];
    $payload = json_decode($body, true);

    $this->postJson('/api/webhooks/settlement', $payload, $headers)->assertOk();
    $this->postJson('/api/webhooks/settlement', $payload, $headers)->assertOk();

    expect($this->wallet->fresh()->balance())->toBe(25000);
});

it('rejects a stale out-of-order delivery without regressing recorded status', function () {
    [$completedBody, $completedSig] = signedWebhookBody([
        'provider_reference' => 'feature-test-005',
        'status' => 'completed',
        'wallet_id' => $this->wallet->id,
        'amount_subunits' => 10000,
        'currency' => 'NGN',
    ]);

    $this->postJson('/api/webhooks/settlement', json_decode($completedBody, true), [
        'X-Settlement-Signature' => $completedSig,
    ])->assertOk();

    [$staleBody, $staleSig] = signedWebhookBody([
        'provider_reference' => 'feature-test-005',
        'status' => 'initiated',
        'wallet_id' => $this->wallet->id,
        'amount_subunits' => 10000,
        'currency' => 'NGN',
    ]);

    $this->postJson('/api/webhooks/settlement', json_decode($staleBody, true), [
        'X-Settlement-Signature' => $staleSig,
    ])->assertOk();

    $event = SettlementWebhookEvent::where('provider_reference', 'feature-test-005')->first();
    expect($event->status->value)->toBe('completed');
});
