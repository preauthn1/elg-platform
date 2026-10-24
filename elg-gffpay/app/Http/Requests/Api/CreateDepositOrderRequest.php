<?php

namespace App\Http\Requests\Api;

use App\Enums\Db\CurrencyCodeEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rules\Enum;

class CreateDepositOrderRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'merchant_code' => 'required|string|max:50',
            'merchant_order_no' => 'required|string|max:50',
            'currency_code' => ['required', new Enum(CurrencyCodeEnum::class)],
            'amount' => [
                'required',
                'numeric',
                'regex:/^\d{1,11}(\.\d{1,9})?$/', // decimal(20,9)
            ],
            'callback_url' => 'required|string|max:191',
            'return_url' => 'nullable|string|max:191',
            'sign' => 'required|string',
        ];
    }
}
