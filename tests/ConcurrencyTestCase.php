<?php

namespace Tests;

use Database\Seeders\SystemAccountsSeeder;

abstract class ConcurrencyTestCase extends TestCase
{
    /**
     * Seed required system accounts before each test.
     */
    protected bool $seed = true;

    protected string $seeder = SystemAccountsSeeder::class;
}
