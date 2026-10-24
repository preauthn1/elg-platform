<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\NewbieChallenge;

use App\Http\Requests\AbstractRequest;

class BlockListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int|null 狀態，見 NewbieChallengeBlockJoinStatusEnum
             *
             * @example 1
             */
            'status' => 'nullable|integer',
            /**
             * @var string|null 玩家帳號（精確比對）
             *
             * @example "vwtest001"
             */
            'member_username' => 'nullable|string|max:50',
            /**
             * @var string|null 代理帳號（精確比對）
             *
             * @example "vwagent001"
             */
            'agent_username' => 'nullable|string|max:50',
            /**
             * @var string|null 最新一筆阻擋時間區間起（前台時間）
             *
             * @example "2026-08-01 00:00"
             */
            'start_time' => 'nullable|string',
            /**
             * @var string|null 最新一筆阻擋時間區間迄（前台時間）
             *
             * @example "2026-08-31 23:59"
             */
            'end_time' => 'nullable|string',
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
