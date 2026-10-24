<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Vendor;

use App\Http\Requests\AbstractRequest;

class ResetPasswordRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 遊戲商ID
             *
             * @example example.invalid
             */
            'vendor_id' => 'required||digits_between:0,10',
            /**
             * 新密碼
             *
             * @example example.invalid
             */
            'new_password' => 'required|alpha_num|between:6,14',
        ];
    }
}
