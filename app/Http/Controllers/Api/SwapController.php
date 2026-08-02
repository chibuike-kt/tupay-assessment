<?php

namespace App\Http\Controllers\Api;

use App\Domain\Swap\SwapService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SwapRequest;
use App\Http\Resources\SwapResource;
use App\Models\User;
use App\Models\Wallet;

class SwapController extends Controller
{
    public function __construct(private readonly SwapService $swaps) {}

    public function __invoke(SwapRequest $request): SwapResource
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $source = Wallet::findOrFail($request->integer('source_wallet_id'));
        $destination = Wallet::findOrFail($request->integer('destination_wallet_id'));

        $swap = $this->swaps->execute($user, $source, $destination, $request->integer('amount_subunits'));

        return new SwapResource($swap);
    }
}
