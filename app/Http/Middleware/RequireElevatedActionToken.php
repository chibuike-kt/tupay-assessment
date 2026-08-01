<?php

namespace App\Http\Middleware;

use App\Domain\Security\ElevatedActionTokenService;
use App\Domain\Security\Exceptions\InvalidElevatedActionTokenException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireElevatedActionToken
{
    public function __construct(private readonly ElevatedActionTokenService $tokens) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = $request->header('X-Elevated-Action-Token');

        if (! $token) {
            return response()->json(['message' => 'Missing X-Elevated-Action-Token header.'], 401);
        }

        try {
            $this->tokens->consume($token, $user, $action, $request->all());
        } catch (InvalidElevatedActionTokenException $e) {
            $status = str_contains($e->getMessage(), 'match') ? 422 : 401;

            return response()->json(['message' => $e->getMessage()], $status);
        }

        return $next($request);
    }
}
