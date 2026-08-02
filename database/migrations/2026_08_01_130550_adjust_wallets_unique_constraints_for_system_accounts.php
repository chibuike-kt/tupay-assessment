<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original (user_id, currency) unique constraint assumed exactly
     * one wallet per user per currency — true for ordinary users, but the
     * system user needs two NGN wallets (fx_clearing, fee_revenue) and two
     * CNY wallets. Replace it with a partial index that only applies to
     * ordinary wallets (label IS NULL); labeled system wallets are
     * constrained separately by the existing (label, currency) unique index.
     */
    public function up(): void
    {
        Schema::table('wallets', function ($table) {
            $table->dropUnique('wallets_user_id_currency_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX wallets_user_currency_ordinary_unique ON wallets (user_id, currency) WHERE label IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS wallets_user_currency_ordinary_unique');

        Schema::table('wallets', function ($table) {
            $table->unique(['user_id', 'currency']);
        });
    }
};
