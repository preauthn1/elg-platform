<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Member;

use App\Http\Requests\AbstractRequest;

class ChangePaymentPasswordRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 目前支付密碼 (6-12 位)
             */
            'current_payment_password' => 'nullable|between:6,12',
            /**
             * 新支付密碼 (6-12 位)
             */
            'new_payment_password' => 'required|between:6,12|confirmed',
        ];
    }
}
