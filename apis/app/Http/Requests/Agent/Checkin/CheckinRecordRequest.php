<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Checkin;

use App\Http\Requests\AbstractRequest;

class CheckinRecordRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 玩家帳號
             *
             * @example "vwtest001"
             */
            'username' => 'nullable|string',
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
             * @example 50
             */
            'per_page' => 'required|integer',
        ];
    }
}
