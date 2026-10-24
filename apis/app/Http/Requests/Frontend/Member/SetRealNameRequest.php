<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Member;

use App\Http\Requests\AbstractRequest;

class SetRealNameRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 真實姓名
             *
             * 只允許中文、英文、空格、-、_，不允許數字或其他特殊字元 (Emoji)
             *
             * @example 小甜甜
             */
            'real_name' => 'required|regex:/^(?!.*\d)[\p{L}\s\-_]+$/u|between:2,60',
        ];
    }
}
