<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberately no `balance` column. Balance is always derived from
     * ledger_entries (see 000004) — there is nothing here to drift out
     * of sync with the ledger, because there is nothing to update.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 3); // ISO 4217: NGN, CNY
            // Null for ordinary user wallets. Set for the platform's own
            // FX clearing / fee-revenue wallets (see SystemAccountsSeeder),
            // so SwapService can look them up without hardcoding IDs.
            $table->string('label')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
            $table->unique(['label', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
