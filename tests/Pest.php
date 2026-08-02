<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature/*.php');

/**
 * Concurrency tests fire real HTTP requests at an externally-running
 * `php artisan serve` process, so they need a real, committed connection
 * to the SAME database that server uses — not RefreshDatabase's wrapping
 * transaction (invisible to other processes) and not phpunit.xml's
 * DB_DATABASE=:memory: sqlite override. CONCURRENCY_DB_* env vars are set
 * explicitly (locally in .env, in CI via the workflow) rather than reusing
 * DB_DATABASE, since phpunit.xml's <env> block overrides that value.
 */
uses(TestCase::class)
    ->beforeEach(function () {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => getenv('CONCURRENCY_DB_DATABASE') ?: 'tupay',
            'database.connections.pgsql.host' => getenv('CONCURRENCY_DB_HOST') ?: '127.0.0.1',
            'database.connections.pgsql.port' => getenv('CONCURRENCY_DB_PORT') ?: '5432',
            'database.connections.pgsql.username' => getenv('CONCURRENCY_DB_USERNAME') ?: 'tupay',
            'database.connections.pgsql.password' => getenv('CONCURRENCY_DB_PASSWORD') ?: 'tupay',
        ]);

        DB::purge('pgsql');
    })
    ->in('Feature/Concurrency');

uses(TestCase::class)->in('Unit');
