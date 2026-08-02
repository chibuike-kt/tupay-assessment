<?php

use App\Http\Controllers\Api\SettlementWebhookController;
use App\Http\Controllers\Api\SwapController;
use App\Http\Controllers\Api\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/2fa/challenge', TwoFactorChallengeController::class)
        ->middleware('throttle:10,1');

    Route::post('/swap', SwapController::class)
        ->middleware('elevated:swap');
});

Route::post('/webhooks/settlement', SettlementWebhookController::class)
    ->middleware('webhook.signature');
