<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\SettlementWebhookController;
use App\Http\Controllers\Api\SwapController;
use App\Http\Controllers\Api\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/2fa/challenge', TwoFactorChallengeController::class)
        ->middleware('throttle:10,1');

    Route::post('/swap', SwapController::class)
        ->middleware('elevated:swap');

    Route::get('/ledger/{wallet}', LedgerController::class);
});

Route::post('/webhooks/settlement', SettlementWebhookController::class)
    ->middleware('webhook.signature');
