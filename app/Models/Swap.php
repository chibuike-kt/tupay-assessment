<?php

namespace App\Models;

use App\Enums\SwapStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Swap extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'transaction_group_id',
        'user_id',
        'source_wallet_id',
        'destination_wallet_id',
        'source_amount_subunits',
        'destination_amount_subunits',
        'fee_subunits',
        'rate_applied',
        'status',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => SwapStatus::class,
            'source_amount_subunits' => 'integer',
            'destination_amount_subunits' => 'integer',
            'fee_subunits' => 'integer',
            'rate_applied' => 'decimal:8',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $swap) {
            $swap->id ??= (string) Str::uuid();
            $swap->transaction_group_id ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function sourceWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'source_wallet_id');
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function destinationWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'destination_wallet_id');
    }
}
