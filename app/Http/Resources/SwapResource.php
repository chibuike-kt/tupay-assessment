<?php

namespace App\Http\Resources;

use App\Models\Swap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Swap */
class SwapResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Swap $swap */
        $swap = $this->resource;

        return [
            'id' => $swap->id,
            // Larastan doesn't resolve the method-based casts() enum type here;
            // verified via tinker that $swap->status is always a real SwapStatus.
            // @phpstan-ignore property.nonObject
            'status' => $swap->status->value,
            'source_wallet_id' => $swap->source_wallet_id,
            'destination_wallet_id' => $swap->destination_wallet_id,
            'source_amount_subunits' => $swap->source_amount_subunits,
            'destination_amount_subunits' => $swap->destination_amount_subunits,
            'fee_subunits' => $swap->fee_subunits,
            'rate_applied' => (string) $swap->rate_applied,
            // Same Larastan casts() limitation; created_at is always set on a
            // persisted Swap loaded from the database.
            // @phpstan-ignore method.nonObject
            'created_at' => $swap->created_at->toIso8601String(),
        ];
    }
}
