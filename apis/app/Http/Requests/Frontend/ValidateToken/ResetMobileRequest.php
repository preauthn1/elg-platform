<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\ValidateToken;

use App\Http\Requests\AbstractRequest;

class ResetMobileRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 驗證碼
             *
             * @example 123456
             */
            'token' => 'required|size:6',
            /**
             * 新手機號
             *
             * @example 123456789
             */
            'new_mobile' => 'required|digits_between:6,12',
        ];
    }
}
