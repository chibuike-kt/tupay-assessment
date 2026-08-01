<?php

use App\Http\Controllers\Api\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/2fa/challenge', TwoFactorChallengeController::class)
        ->middleware('throttle:10,1');
});
