<?php

namespace App\Http\Controllers\Api;

use App\Domain\Security\ElevatedActionTokenService;
use App\Domain\Security\TotpVerifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChallengeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class TwoFactorChallengeController extends Controller
{
  public function __construct(
    private readonly TotpVerifier $totpVerifier,
    private readonly ElevatedActionTokenService $tokens,
  ) {}

  public function __invoke(ChallengeRequest $request): JsonResponse
  {
    $user = $request->user();

    if (! $user instanceof User) {
      abort(401);
    }

    if (! $this->totpVerifier->verify($user, $request->string('totp_code')->toString())) {
      return response()->json(['message' => 'Invalid TOTP code.'], 401);
    }

    $token = $this->tokens->issue($user, $request->string('action')->toString(), $request->array('action_payload'));

    return response()->json([
      'elevated_action_token' => $token,
      'expires_in' => (int) config('security.eat.ttl_seconds'),
    ]);
  }
}
