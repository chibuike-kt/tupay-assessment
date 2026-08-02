<?php

namespace Database\Seeders;

use App\Domain\Swap\SystemAccounts;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

class SystemAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $systemUser = User::firstOrCreate(
            ['email' => 'system@tupay.internal'],
            ['name' => 'Tupay System', 'password' => bcrypt(str()->random(40))],
        );

        foreach (['NGN', 'CNY'] as $currency) {
            Wallet::firstOrCreate([
                'label' => SystemAccounts::LABEL_FX_CLEARING,
                'currency' => $currency,
            ], [
                'user_id' => $systemUser->id,
            ]);

            Wallet::firstOrCreate([
                'label' => SystemAccounts::LABEL_FEE_REVENUE,
                'currency' => $currency,
            ], [
                'user_id' => $systemUser->id,
            ]);
        }
    }
}
