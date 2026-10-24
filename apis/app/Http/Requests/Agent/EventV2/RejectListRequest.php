<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EventV2;

use App\Http\Requests\AbstractRequest;

class RejectListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 活動ID
             *
             * @example 2
             */
            'event_v2_id' => 'nullable|integer',
            /**
             * @var string 代理帳號
             *
             * @example "frank"
             */
            'agent_username' => 'nullable|string',
            /**
             * @var string 會員帳號
             *
             * @example "daniel01"
             */
            'member_username' => 'nullable|string',
            /**
             * @var string 開始時間
             *
             * @example "2025-06-15 00:00:00"
             */
            'start_time' => 'nullable|string',
            /**
             * @var string 結束時間
             *
             * @example "2025-06-16 00:00:00"
             */
            'end_time' => 'nullable|string',
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer',
            /**
             * @var int 每頁筆數
             *
             * @example 10
             */
            'per_page' => 'required|integer',
        ];
    }
}
