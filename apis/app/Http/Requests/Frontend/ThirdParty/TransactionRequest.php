<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\ThirdParty;

use Illuminate\Foundation\Http\FormRequest;

class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'username' => 'required|string|between:4,30',
            'agent_username' => 'required|string|between:1,30',
            'platform' => 'nullable|in:1,2',
            'amount' => 'required|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'transaction_type' => 'required|integer|in:4,5,6,7,8,9,10,11,12,14',
            'audit_amount' => 'nullable|numeric|min:0',
            'remark' => 'nullable|string|max:191',
            'operator' => 'nullable|string|max:191',
            'role_id' => 'nullable|integer',
            'current_balance' => 'nullable|numeric|min:0',
        ];
    }
}
