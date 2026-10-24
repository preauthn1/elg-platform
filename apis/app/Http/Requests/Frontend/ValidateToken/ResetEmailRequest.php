<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\ValidateToken;

use App\Http\Requests\AbstractRequest;

class ResetEmailRequest extends AbstractRequest
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
             * 新信箱
             *
             * @example user@example.invalid
             */
            'new_email' => 'required|email|between:3,60',
        ];
    }
}
