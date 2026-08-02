<?php

namespace App\Http\Resources;

use App\Enums\LedgerDirection;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LedgerEntry */
class LedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LedgerEntry $entry */
        $entry = $this->resource;

        // Larastan doesn't resolve the method-based casts() enum type here;
        // verified via tinker that direction is always a real LedgerDirection.
        // @phpstan-ignore property.nonObject
        $direction = $entry->direction->value;

        return [
            'id' => $entry->id,
            'transaction_group_id' => $entry->transaction_group_id,
            'direction' => $direction,
            'amount_subunits' => $entry->amount_subunits,
            'currency' => $entry->currency,
            'reference' => $entry->reference,
            'description' => $entry->description,
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }
}
