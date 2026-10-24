<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\NewbieChallenge;

use App\Http\Requests\AbstractRequest;

class OperationLogListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int|null newbie_challenge_progress.id，供詳情頁下鑽單一會員的操作紀錄
             *
             * @example 1
             */
            'progress_id' => 'nullable|integer|min:1',
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
             * @var string|null 操作時間區間起（前台時間）
             *
             * @example "2026-08-01 00:00"
             */
            'start_time' => 'nullable|string',
            /**
             * @var string|null 操作時間區間迄（前台時間）
             *
             * @example "2026-08-13 23:59"
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
