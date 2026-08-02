<?php

namespace App\Http\Controllers\Api;

use App\Domain\Webhooks\SettlementWebhookProcessor;
use App\Enums\WebhookStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SettlementWebhookRequest;
use Illuminate\Http\JsonResponse;

class SettlementWebhookController extends Controller
{
    public function __construct(private readonly SettlementWebhookProcessor $processor) {}

    public function __invoke(SettlementWebhookRequest $request): JsonResponse
    {
        $this->processor->ingest(
            $request->string('provider_reference')->toString(),
            WebhookStatus::from($request->string('status')->toString()),
            $request->except(['provider_reference', 'status']),
        );

        return response()->json(['received' => true]);
    }
}
