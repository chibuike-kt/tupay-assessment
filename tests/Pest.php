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
 * DB_DATABASE=:memory: sqlite override (env() can't see past that, since
 * PHPUnit sets real process env vars that take priority over .env).
 * These values must match your local docker-compose Postgres exactly.
 */
uses(TestCase::class)
    ->beforeEach(function () {
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'tupay',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.username' => 'tupay',
            'database.connections.pgsql.password' => 'tupay',
        ]);

        DB::purge('pgsql');
    })
    ->in('Feature/Concurrency');

uses(TestCase::class)->in('Unit');
