<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Member;

use App\Http\Requests\AbstractRequest;

class MemberTraceListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 用戶名(反查模式：取該會員的值反查關聯帳號)
             *
             * @example "frank"
             */
            'username' => 'nullable|string|max:191',
            /**
             * @var array 反查維度(取用戶名對應會員的哪些值反查)
             *
             * @example ["ip","password"]
             */
            'trace_fields' => 'nullable|array',
            'trace_fields.*' => 'string|in:ip,password,mobile,email,realname,bank',
            /**
             * @var string IP 位址(完整 IPv4)
             *
             * @example "0.0.0.0"
             */
            'ip' => 'nullable|ipv4',
            /**
             * @var string 聯絡電話
             *
             * @example "601232109309"
             */
            'mobile' => 'nullable|string|max:191',
            /**
             * @var string 電子郵件
             *
             * @example "user@example.invalid"
             */
            'email' => 'nullable|string|max:191',
            /**
             * @var bool 是否使用 AND 條件搜尋(true:全部條件成立 false:任一條件成立)
             *
             * @example true
             */
            'use_and' => 'nullable|boolean',
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer|min:1',
            /**
             * @var int 每頁筆數(上限 100)
             *
             * @example 25
             */
            'per_page' => 'required|integer|min:1|max:100',
        ];
    }
}
