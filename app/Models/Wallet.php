<?php

namespace App\Models;

use App\Enums\LedgerDirection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    protected $fillable = ['user_id', 'currency', 'label'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * @param  Builder<Wallet>  $query
     * @return Builder<Wallet>
     */
    public function scopeLabeled(Builder $query, string $label, string $currency): Builder
    {
        return $query->where('label', $label)->where('currency', $currency);
    }

    public function balance(): int
    {
        return (int) $this->ledgerEntries()
            ->selectRaw('COALESCE(SUM(CASE WHEN direction = ? THEN amount_subunits ELSE -amount_subunits END), 0) as balance', [
                LedgerDirection::Credit->value,
            ])
            ->value('balance');
    }

    public function lockedBalance(): int
    {
        static::query()->whereKey($this->id)->lockForUpdate()->value('id');

        return $this->balance();
    }
}
