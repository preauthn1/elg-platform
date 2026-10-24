<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\ThirdParty;

use Illuminate\Foundation\Http\FormRequest;

class NewAgentTransactionRequest extends FormRequest
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
            'from' => 'required|string|between:1,30',
            'to' => 'required|string|between:1,30',
            'type' => 'required|integer|in:1,2',
            'amount' => 'required|numeric|min:0',
            'remark' => 'nullable|string|max:191',
            'operator' => 'nullable|string|max:191',
            'status' => 'nullable|boolean',
        ];
    }
}
