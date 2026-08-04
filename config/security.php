<?php

return [
    'eat' => [
        'ttl_seconds' => (int) env('EAT_TTL_SECONDS', 60),
        'signing_key' => env('EAT_SIGNING_KEY'),
    ],

    'settlement_webhook' => [
        'secret' => env('SETTLEMENT_WEBHOOK_SECRET'),
    ],
];
