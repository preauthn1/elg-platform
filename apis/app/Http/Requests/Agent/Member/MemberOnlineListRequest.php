<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Member;

use App\Http\Requests\AbstractRequest;

class MemberOnlineListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 玩家帳號（精確比對）
             *
             * @example "vwtest001"
             */
            'username' => 'nullable|string|max:50',
            /**
             * @var string 真實姓名（模糊比對）
             *
             * @example "王小明"
             */
            'name' => 'nullable|string|max:50',
            /**
             * @var string 最後登入IP（精確比對）
             *
             * @example "0.0.0.0"
             */
            'ip' => 'nullable|string|max:45',
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer|min:1',
            /**
             * @var int 每頁筆數
             *
             * @example 25
             */
            'per_page' => 'required|integer|min:1',
        ];
    }
}
