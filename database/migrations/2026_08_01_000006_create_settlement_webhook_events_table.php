<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * provider_reference carries the idempotency guarantee at the DB layer
     * (unique constraint), backed by an atomic Redis SETNX check on the hot
     * path so we never even attempt a duplicate insert under load.
     *
     * `status` is a small state machine (initiated -> processing -> completed
     * / failed) so out-of-order delivery (COMPLETED before INITIATED) is
     * resolved by comparing state transitions rather than trusting arrival order.
     */
    public function up(): void
    {
        Schema::create('settlement_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_reference')->unique();
            $table->string('status', 20); // initiated, processing, completed, failed
            $table->jsonb('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_webhook_events');
    }
};
