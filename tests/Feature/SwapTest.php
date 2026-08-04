<?php

use App\Domain\Swap\RateClient;
use App\Domain\Swap\SystemAccounts;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    // Deterministic rate, no network dependency — same pattern proven
    // manually in tinker throughout this project's build.
    $this->app->bind(RateClient::class, fn () => new class implements RateClient
    {
        public function fetch(string $base, string $quote): string
        {
            return '0.0048';
        }
    });

    // System accounts required by SwapService's ledger posting.
    foreach (['NGN', 'CNY'] as $currency) {
        Wallet::firstOrCreate(
            ['label' => SystemAccounts::LABEL_FX_CLEARING, 'currency' => $currency],
            ['user_id' => User::factory()->create()->id],
        );
        Wallet::firstOrCreate(
            ['label' => SystemAccounts::LABEL_FEE_REVENUE, 'currency' => $currency],
            ['user_id' => User::factory()->create()->id],
        );
    }

    $this->google2fa = new Google2FA;
    $this->secret = $this->google2fa->generateSecretKey();

    $this->user = User::factory()->create();
    $this->user->forceFill(['totp_secret' => $this->secret, 'totp_confirmed_at' => now()])->save();

    $this->source = $this->user->wallets()->create(['currency' => 'NGN']);
    $this->destination = $this->user->wallets()->create(['currency' => 'CNY']);

    $this->source->ledgerEntries()->create([
        'id' => (string) Str::uuid(),
        'transaction_group_id' => (string) Str::uuid(),
        'direction' => 'credit',
        'amount_subunits' => 100000,
        'currency' => 'NGN',
        'description' => 'Test funding',
    ]);
});

function challengeAndGetEat($test, array $payload): string
{
    $code = $test->google2fa->getCurrentOtp($test->secret);

    $response = $test->actingAs($test->user, 'sanctum')->postJson('/api/2fa/challenge', [
        'totp_code' => $code,
        'action' => 'swap',
        'action_payload' => $payload,
    ]);

    return $response->json('elevated_action_token');
}

it('executes a swap end to end with a valid EAT', function () {
    $payload = [
        'source_wallet_id' => $this->source->id,
        'destination_wallet_id' => $this->destination->id,
        'amount_subunits' => 50000,
    ];

    $eat = challengeAndGetEat($this, $payload);

    $response = $this->actingAs($this->user, 'sanctum')
        ->withHeaders(['X-Elevated-Action-Token' => $eat])
        ->postJson('/api/swap', $payload);

    $response->assertCreated();
    $response->assertJsonPath('data.status', 'completed');
    $response->assertJsonPath('data.destination_amount_subunits', 240);

    expect($this->source->fresh()->balance())->toBe(50000);
    expect($this->destination->fresh()->balance())->toBe(240);
});

it('rejects a swap with no elevated action token', function () {
    $payload = [
        'source_wallet_id' => $this->source->id,
        'destination_wallet_id' => $this->destination->id,
        'amount_subunits' => 50000,
    ];

    $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/swap', $payload);

    $response->assertUnauthorized();
    $response->assertJson(['message' => 'Missing X-Elevated-Action-Token header.']);
});

it('rejects replay of an already-consumed EAT', function () {
    $payload = [
        'source_wallet_id' => $this->source->id,
        'destination_wallet_id' => $this->destination->id,
        'amount_subunits' => 10000,
    ];

    $eat = challengeAndGetEat($this, $payload);

    $this->actingAs($this->user, 'sanctum')
        ->withHeaders(['X-Elevated-Action-Token' => $eat])
        ->postJson('/api/swap', $payload)
        ->assertCreated();

    $replay = $this->actingAs($this->user, 'sanctum')
        ->withHeaders(['X-Elevated-Action-Token' => $eat])
        ->postJson('/api/swap', $payload);

    $replay->assertUnauthorized();
});

it('rejects a swap whose payload does not match the EAT-bound payload', function () {
    $eat = challengeAndGetEat($this, [
        'source_wallet_id' => $this->source->id,
        'destination_wallet_id' => $this->destination->id,
        'amount_subunits' => 10000,
    ]);

    $tampered = [
        'source_wallet_id' => $this->source->id,
        'destination_wallet_id' => $this->destination->id,
        'amount_subunits' => 999999, // different from what was hashed
    ];

    $response = $this->actingAs($this->user, 'sanctum')
        ->withHeaders(['X-Elevated-Action-Token' => $eat])
        ->postJson('/api/swap', $tampered);

    $response->assertUnprocessable();
});

it('rejects a swap exceeding the available balance', function () {
    $payload = [
        'source_wallet_id' => $this->source->id,
        'destination_wallet_id' => $this->destination->id,
        'amount_subunits' => 99999999,
    ];

    $eat = challengeAndGetEat($this, $payload);

    $response = $this->actingAs($this->user, 'sanctum')
        ->withHeaders(['X-Elevated-Action-Token' => $eat])
        ->postJson('/api/swap', $payload);

    $response->assertUnprocessable();
    $response->assertJson(['message' => 'Source wallet does not have sufficient balance for this swap.']);
});
