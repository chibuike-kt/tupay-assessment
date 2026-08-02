<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LedgerEntryResource;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LedgerController extends Controller
{
    public function __invoke(Request $request, Wallet $wallet): AnonymousResourceCollection
    {
        $user = $request->user();

        if (! $user instanceof User || $wallet->user_id !== $user->id) {
            // 404, not 403 — don't confirm to a caller that a wallet ID
            // belonging to someone else even exists.
            throw new NotFoundHttpException;
        }

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $entries = $wallet->ledgerEntries()
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return LedgerEntryResource::collection($entries);
    }
}
