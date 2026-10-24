<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Member;

use App\Http\Requests\AbstractRequest;

class ChangePasswordRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 目前密碼；只拿來比對，不套用密碼規則（規則上線前設定的舊密碼也要能用）
             */
            'password' => 'required|string',
            /**
             * 新密碼；字數與字元規則依代理的註冊欄位設定，於 controller 檢查
             */
            'new_password' => 'required|string|confirmed',
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => __('error_message.1018'),
            'new_password.required' => __('error_message.1019'),
        ];
    }
}
