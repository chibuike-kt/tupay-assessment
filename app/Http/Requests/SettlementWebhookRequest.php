<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettlementWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider_reference' => ['required', 'string'],
            'status' => ['required', 'string', Rule::in(['initiated', 'processing', 'completed', 'failed'])],
            'wallet_id' => ['required_if:status,completed', 'integer'],
            'amount_subunits' => ['required_if:status,completed', 'integer', 'min:1'],
            'currency' => ['required_if:status,completed', 'string', 'size:3'],
        ];
    }
}
