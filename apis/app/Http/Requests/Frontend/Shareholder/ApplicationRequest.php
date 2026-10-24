<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Shareholder;

use App\Http\Requests\AbstractRequest;
use App\Rules\Username;

class ApplicationRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 帳號 (不可重覆)
             *
             * @example joanna
             */
            'username' => ['required', 'string', 'between:4,20', 'unique:agents', new Username],
            /**
             * 密碼
             *
             * @example ******
             */
            'password' => 'required|between:4,15|confirmed',
        ];
    }
}
