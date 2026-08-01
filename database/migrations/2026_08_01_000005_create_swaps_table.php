<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per swap attempt, independent of the ledger. Lets us record
     * rejected/failed attempts (for the concurrency test's 409/422 assertions)
     * without ever writing unbalanced or partial ledger entries for them.
     */
    public function up(): void
    {
        Schema::create('swaps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_group_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('destination_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->unsignedBigInteger('source_amount_subunits');
            $table->unsignedBigInteger('destination_amount_subunits');
            $table->unsignedBigInteger('fee_subunits')->default(0);
            $table->decimal('rate_applied', 18, 8);
            $table->string('status', 20); // pending, completed, failed
            $table->string('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swaps');
    }
};
