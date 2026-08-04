<?php

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->google2fa = new Google2FA;
    $this->secret = $this->google2fa->generateSecretKey();

    $this->user = User::factory()->create();
    $this->user->forceFill([
        'totp_secret' => $this->secret,
        'totp_confirmed_at' => now(),
    ])->save();
});

it('issues an elevated action token for a valid TOTP code', function () {
    $code = $this->google2fa->getCurrentOtp($this->secret);

    $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/2fa/challenge', [
        'totp_code' => $code,
        'action' => 'swap',
        'action_payload' => [
            'source_wallet_id' => 1,
            'destination_wallet_id' => 2,
            'amount_subunits' => 50000,
        ],
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['elevated_action_token', 'expires_in']);
    expect($response->json('expires_in'))->toBe(60);
});

it('rejects an invalid TOTP code', function () {
    $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/2fa/challenge', [
        'totp_code' => '000000',
        'action' => 'swap',
        'action_payload' => [
            'source_wallet_id' => 1,
            'destination_wallet_id' => 2,
            'amount_subunits' => 50000,
        ],
    ]);

    $response->assertUnauthorized();
    $response->assertJson(['message' => 'Invalid TOTP code.']);
});

it('requires authentication', function () {
    $response = $this->postJson('/api/2fa/challenge', [
        'totp_code' => '123456',
        'action' => 'swap',
        'action_payload' => ['source_wallet_id' => 1, 'destination_wallet_id' => 2, 'amount_subunits' => 50000],
    ]);

    $response->assertUnauthorized();
});

it('validates the action_payload shape for a swap action', function () {
    $code = $this->google2fa->getCurrentOtp($this->secret);

    $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/2fa/challenge', [
        'totp_code' => $code,
        'action' => 'swap',
        'action_payload' => ['source_wallet_id' => 1], // missing required fields
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['action_payload.destination_wallet_id', 'action_payload.amount_subunits']);
});
