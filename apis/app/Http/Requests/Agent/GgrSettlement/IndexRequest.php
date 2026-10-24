<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GgrSettlement;

use App\Http\Requests\AbstractRequest;

/**
 * 推廣代理 GGR 派發紀錄 列表 工單4125
 */
class IndexRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 代理帳號關鍵字
             *
             * @example "agent01"
             */
            'agent_username' => 'nullable|string',
            /**
             * @var int 結轉狀態 1:有結轉 0:無結轉
             *
             * @example 1
             */
            'carry' => 'nullable|in:0,1',
            /**
             * @var int 發放狀態 0:未發放 1:已發放
             *
             * @example 0
             */
            'payout_status' => 'nullable|in:0,1',
            /**
             * @var string 結算區間起（對 period_start）
             *
             * @example "2026-09-01"
             */
            'start_date' => 'nullable|date_format:Y-m-d',
            /**
             * @var string 結算區間迄（對 period_start）
             *
             * @example "2026-09-30"
             */
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer',
            /**
             * @var int 每頁筆數
             *
             * @example 20
             */
            'per_page' => 'required|integer',
        ];
    }
}
