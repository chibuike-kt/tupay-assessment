<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChallengeRequest extends FormRequest
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
            'totp_code' => ['required', 'string', 'digits:6'],
            'action' => ['required', 'string', 'in:swap'],
            'action_payload' => ['required', 'array'],
            'action_payload.source_wallet_id' => ['required_if:action,swap', 'integer'],
            'action_payload.destination_wallet_id' => ['required_if:action,swap', 'integer'],
            'action_payload.amount_subunits' => ['required_if:action,swap', 'integer', 'min:1'],
        ];
    }
}
