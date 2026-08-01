<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SwapRequest extends FormRequest
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
        $userId = $this->user()?->id;

        return [
            'source_wallet_id' => [
                'required',
                'integer',
                Rule::exists('wallets', 'id')->where('user_id', $userId),
            ],
            'destination_wallet_id' => [
                'required',
                'integer',
                'different:source_wallet_id',
                Rule::exists('wallets', 'id')->where('user_id', $userId),
            ],
            'amount_subunits' => ['required', 'integer', 'min:1'],
        ];
    }
}
