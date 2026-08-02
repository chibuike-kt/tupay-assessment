<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TestUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('TestUsersSeeder only runs in local/testing environments.');

            return;
        }

        $google2fa = new Google2FA;

        $user = User::firstOrCreate(
            ['email' => 'demo@tupay.dev'],
            [
                'name' => 'Demo User',
                'password' => 'password',
            ],
        );

        $secret = $google2fa->generateSecretKey();

        $user->forceFill([
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
        ])->save();

        $ngnWallet = $user->wallets()->firstOrCreate(['currency' => 'NGN']);
        $cnyWallet = $user->wallets()->firstOrCreate(['currency' => 'CNY']);

        // Fund with a real ledger entry, not a raw balance write — there is
        // no balance column to write to, by design (see wallets migration).
        if ($ngnWallet->balance() === 0) {
            $ngnWallet->ledgerEntries()->create([
                'id' => (string) Str::uuid(),
                'transaction_group_id' => Str::uuid(),
                'direction' => 'credit',
                'amount_subunits' => 500_000_00,
                'currency' => 'NGN',
                'description' => 'Seeded starting balance',
            ]);
        }

        if ($cnyWallet->balance() === 0) {
            $cnyWallet->ledgerEntries()->create([
                'id' => (string) Str::uuid(),
                'transaction_group_id' => Str::uuid(),
                'direction' => 'credit',
                'amount_subunits' => 10_000_00,
                'currency' => 'CNY',
                'description' => 'Seeded starting balance',
            ]);
        }

        $this->command?->info('Demo user: demo@tupay.dev / password');
        $this->command?->info("TOTP secret: {$secret}");
        $this->command?->info('NGN wallet id: '.$ngnWallet->id.' | CNY wallet id: '.$cnyWallet->id);

    }
}
