<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Mission;

use App\Http\Requests\AbstractRequest;

class ClaimRecordRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 會員帳號
             *
             * @example "member01"
             */
            'member_username' => 'nullable|string',
            /**
             * @var string 獎勵類型
             *
             * @example "balance"
             */
            'reward_type' => 'nullable|string',
            /**
             * @var int 領取狀態
             *
             * @example 1
             */
            'is_claimed' => 'nullable|integer',
            /**
             * @var string 搜尋時間欄位
             *
             * @example "claim_time"
             */
            'time_column' => 'nullable|string',
            /**
             * @var string 開始時間
             *
             * @example "2025-06-01 00:00"
             */
            'start_time' => 'nullable|string',
            /**
             * @var string 結束時間
             *
             * @example "2026-06-01 23:59"
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
