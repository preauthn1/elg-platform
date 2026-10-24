<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\TransactionLog;

use App\Http\Requests\AbstractRequest;

class WalletLogsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 錢包類型
             *
             * @example main=主錢包, game=遊戲錢包
             */
            'wallet_type' => 'nullable|string|in:main,game',
            /**
             * 開始時間
             *
             * @example 2025-01-01 00:00:00
             */
            'start_time' => 'nullable|date_format:Y-m-d H:i:s',
            /**
             * 結束時間
             *
             * @example 2025-12-31 23:59:59
             */
            'end_time' => 'nullable|date_format:Y-m-d H:i:s',
            /**
             * 頁碼
             *
             * @example 1
             */
            'page' => 'nullable|integer',
            /**
             * 每頁筆數
             *
             * @example 15
             */
            'per_page' => 'nullable|integer',
        ];
    }
}
