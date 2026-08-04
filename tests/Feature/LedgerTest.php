<?php

use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->wallet = $this->user->wallets()->create(['currency' => 'NGN']);

    $this->wallet->ledgerEntries()->create([
        'id' => (string) Str::uuid(),
        'transaction_group_id' => (string) Str::uuid(),
        'direction' => 'credit',
        'amount_subunits' => 100000,
        'currency' => 'NGN',
        'description' => 'Test entry',
    ]);
});

it('returns paginated ledger entries for a wallet the user owns', function () {
    $response = $this->actingAs($this->user, 'sanctum')->getJson("/api/ledger/{$this->wallet->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.amount_subunits', 100000);
    $response->assertJsonPath('data.0.direction', 'credit');
});

it('hides a wallet belonging to a different user with a 404, not a 403', function () {
    $otherUser = User::factory()->create();
    $otherWallet = $otherUser->wallets()->create(['currency' => 'NGN']);

    $response = $this->actingAs($this->user, 'sanctum')->getJson("/api/ledger/{$otherWallet->id}");

    $response->assertNotFound();
});

it('requires authentication', function () {
    $response = $this->getJson("/api/ledger/{$this->wallet->id}");

    $response->assertUnauthorized();
});
