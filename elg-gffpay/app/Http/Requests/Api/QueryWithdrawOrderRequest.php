<?php

namespace App\Http\Requests\Api;

use App\Enums\Db\CurrencyCodeEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rules\Enum;

class QueryWithdrawOrderRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'merchant_code' => 'required|string|max:50',
            'merchant_order_no' => 'required|string|max:50',
            'currency_code' => ['required', new Enum(CurrencyCodeEnum::class)],
            'sign' => 'required|string',
        ];
    }
}
