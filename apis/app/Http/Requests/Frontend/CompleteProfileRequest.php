<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend;

use App\Http\Requests\AbstractRequest;

/**
 * TG / 會員補資料整合請求（3969）。
 *
 * 一次收齊補資料所需欄位；「哪些必填」由代理的 3970 註冊設定於 service 動態判定，
 * 此處僅做基礎格式驗證、皆為 nullable。
 */
class CompleteProfileRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'real_name' => ['nullable', 'string', 'between:1,60'],
            'payment_password' => ['nullable', 'string'],
            'mobile' => ['nullable', 'string', 'digits_between:1,20'],
            'mobile_token' => ['nullable', 'string', 'size:6'],
            'email' => ['nullable', 'string', 'email', 'between:1,60'],
            'email_token' => ['nullable', 'string', 'size:6'],
            'birthday' => ['nullable', 'date_format:Y-m-d'],
            'domain' => ['nullable', 'string'],
        ];
    }
}
