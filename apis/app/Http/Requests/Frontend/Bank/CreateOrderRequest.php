<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Bank;

use App\Http\Requests\AbstractRequest;

class CreateOrderRequest extends AbstractRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'bank_id' => 'integer|exists:bank_accounts,id',
            'amount' => 'numeric|min:0',
        ];
    }
}
