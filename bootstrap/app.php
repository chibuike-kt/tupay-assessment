<?php

use App\Domain\Security\Exceptions\InvalidElevatedActionTokenException;
use App\Http\Middleware\RequireElevatedActionToken;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'elevated' => RequireElevatedActionToken::class,
            'webhook.signature' => VerifyWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (InvalidElevatedActionTokenException $e) => response()->json([
            'message' => $e->getMessage(),
        ], 401));
    })->create();
