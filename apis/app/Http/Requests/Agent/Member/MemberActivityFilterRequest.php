<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Member;

use App\Http\Requests\AbstractRequest;

class MemberActivityFilterRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var array 篩選條件（key=維度，值為 {op,value,value2} 或 {start,end}）
             *
             * @example {"total_deposit_amount":{"op":"gt","value":100},"last_login":{"start":"2026-07-01","end":"2026-07-22"}}
             */
            'filters' => 'nullable|array',
            /**
             * @var string 條件邏輯 and:全部符合 or:任一符合
             *
             * @example "and"
             */
            'match' => 'nullable|string|in:and,or',
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer|min:1',
            /**
             * @var int 每頁筆數
             *
             * @example 15
             */
            'per_page' => 'required|integer|min:1',
        ];
    }
}
