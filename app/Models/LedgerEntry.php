<?php

namespace App\Models;

use App\Enums\LedgerDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LedgerEntry extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'transaction_group_id',
        'wallet_id',
        'direction',
        'amount_subunits',
        'currency',
        'reference',
        'description',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'direction' => LedgerDirection::class,
            'amount_subunits' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            $entry->id ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
