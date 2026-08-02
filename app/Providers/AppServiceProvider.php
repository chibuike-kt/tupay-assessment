<?php

namespace App\Providers;

use App\Domain\Swap\HttpRateClient;
use App\Domain\Swap\RateClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(RateClient::class, HttpRateClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
