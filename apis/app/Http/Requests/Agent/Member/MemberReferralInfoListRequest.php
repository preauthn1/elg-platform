<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Member;

use App\Http\Requests\AbstractRequest;

class MemberReferralInfoListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 代理商帳號（精確比對）
             *
             * @example "gfftest"
             */
            'agent_username' => 'nullable|string|max:50',
            /**
             * @var string 玩家帳號（精確比對）
             *
             * @example "vwtest001"
             */
            'username' => 'nullable|string|max:50',
            /**
             * @var string 統計起始時間（前端時區）
             *
             * @example "2026-08-01 00:00"
             */
            'start_time' => 'required|date',
            /**
             * @var string 統計結束時間（前端時區）
             *
             * @example "2026-08-07 23:59"
             */
            'end_time' => 'required|date|after_or_equal:start_time',
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
