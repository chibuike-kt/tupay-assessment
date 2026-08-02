<?php

use App\Http\Controllers\MockRateController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/mock-rates', MockRateController::class);
