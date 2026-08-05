<?php

use Database\Seeders\SystemAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\ConcurrencyTestCase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function () {
        // Redis isolation, mirroring the tupay_testing Postgres database:
        // FLUSHDB only clears the currently-selected DB (REDIS_DB=1 here,
        // set in phpunit.xml), never touching dev's DB 0 — so nothing this
        // suite does to Redis can ever leak into or out of manual testing.
        Redis::connection()->flushdb();
    })
    ->in('Feature/*.php');

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function () {
        Redis::connection()->flushdb();
    })
    ->in('Unit');

/**
 * Concurrency tests fire real HTTP requests at an externally-running
 * `php artisan serve` process, so they need a real, committed connection
 * to the SAME database that server uses — not RefreshDatabase's wrapping
 * transaction (invisible to other processes) and not phpunit.xml's
 * DB_DATABASE=:memory: sqlite override. CONCURRENCY_DB_* env vars are set
 * explicitly (locally in .env, in CI via the workflow) rather than reusing
 * DB_DATABASE, since phpunit.xml's <env> block overrides that value.
 * Redis is intentionally NOT flushed here — the live server process reads
 * dev's real REDIS_DB (0), separate from this file's DB 1, so there's
 * nothing of ours to clean up and nothing of dev's to protect from us.
 */
uses(ConcurrencyTestCase::class)
    ->beforeEach(function () {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => env('CONCURRENCY_DB_DATABASE', 'tupay'),
            'database.connections.pgsql.host' => env('CONCURRENCY_DB_HOST', '127.0.0.1'),
            'database.connections.pgsql.port' => env('CONCURRENCY_DB_PORT', 5432),
            'database.connections.pgsql.username' => env('CONCURRENCY_DB_USERNAME', 'tupay'),
            'database.connections.pgsql.password' => env('CONCURRENCY_DB_PASSWORD', 'tupay'),
        ]);

        DB::purge('pgsql');

        $this->seed(SystemAccountsSeeder::class);
    })
    ->in('Feature/Concurrency');
