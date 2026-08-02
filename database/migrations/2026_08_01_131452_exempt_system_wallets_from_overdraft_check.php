<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * System clearing/fee wallets are the ledger's counterparty for value
     * entering or leaving the platform (an FX clearing account is expected
     * to run negative — that's what it's for). The overdraft guard should
     * only protect real user balances, so we join to wallets and skip the
     * check when the wallet carries a label.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_wallet_overdraft() RETURNS TRIGGER AS $$
            DECLARE
                current_balance BIGINT;
                wallet_label VARCHAR;
            BEGIN
                SELECT label INTO wallet_label FROM wallets WHERE id = NEW.wallet_id;

                IF wallet_label IS NOT NULL THEN
                    RETURN NEW;
                END IF;

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
        SQL);
    }

    public function down(): void
    {
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
        SQL);
    }
};
