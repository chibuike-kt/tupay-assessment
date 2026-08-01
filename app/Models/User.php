<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<Factory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token', 'totp_secret'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'totp_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'totp_secret' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<Wallet, $this>
     */
    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function hasTotpConfigured(): bool
    {
        return $this->totp_secret !== null && $this->totp_confirmed_at !== null;
    }
}
