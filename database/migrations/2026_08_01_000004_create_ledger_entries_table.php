<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only double-entry ledger. A wallet's balance is never stored —
     * it is SUM(credit) - SUM(debit) over this table, always computed.
     *
     * Two layers of protection against overdraft:
     *   1. App layer: SwapService validates balance under a row lock before insert.
     *   2. DB layer (this migration): an AFTER INSERT trigger recomputes the
     *      wallet's balance and raises if it goes negative — this holds even
     *      against a raw psql session or a future bug in application code.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Pairs the debit + credit rows that make up one balanced posting.
            $table->uuid('transaction_group_id')->index();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->enum('direction', ['debit', 'credit']);
            $table->unsignedBigInteger('amount_subunits');
            $table->string('currency', 3);
            $table->string('reference')->nullable()->index();
            $table->string('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Fast paginated GET /api/ledger/{wallet_id}: this is the exact
            // (wallet_id, created_at) shape the endpoint filters and sorts by,
            // so pagination never falls back to a table scan as the ledger grows.
            $table->index(['wallet_id', 'created_at']);
        });

        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT amount_positive CHECK (amount_subunits > 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_wallet_overdraft() RETURNS TRIGGER AS $$
            DECLARE
                current_balance BIGINT;
            BEGIN
                SELECT COALESCE(SUM(
                    CASE WHEN direction = 'credit' THEN amount_subunits ELSE -amount_subunits END
                ), 0)
                INTO current_balance
                FROM ledger_entries
                WHERE wallet_id = NEW.wallet_id;

                IF current_balance < 0 THEN
                    RAISE EXCEPTION 'wallet % overdraft: balance would be % subunits', NEW.wallet_id, current_balance
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_prevent_wallet_overdraft
                AFTER INSERT ON ledger_entries
                FOR EACH ROW
                EXECUTE FUNCTION prevent_wallet_overdraft();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_prevent_wallet_overdraft ON ledger_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_wallet_overdraft');
        Schema::dropIfExists('ledger_entries');
    }
};
