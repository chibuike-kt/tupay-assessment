<?php

namespace App\Models;

use App\Enums\WebhookStatus;
use Illuminate\Database\Eloquent\Model;

class SettlementWebhookEvent extends Model
{
    protected $fillable = ['provider_reference', 'status', 'payload', 'received_at', 'processed_at'];

    protected function casts(): array
    {
        return [
            'status' => WebhookStatus::class,
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
