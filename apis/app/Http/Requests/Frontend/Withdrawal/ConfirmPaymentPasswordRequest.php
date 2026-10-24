<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Withdrawal;

use App\Http\Requests\AbstractRequest;

class ConfirmPaymentPasswordRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 支付密碼 (6-12 位)
             */
            'payment_password' => 'required|between:6,12',
        ];
    }
}
