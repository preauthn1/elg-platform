<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\TransactionLog;

use App\Http\Requests\AbstractRequest;

class GameTransferLogsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            // 遊戲商ID
            'vendor_id' => 'nullable|integer',
            // 開始時間
            'start_time' => 'nullable|date_format:Y-m-d H:i:s',
            // 結束時間
            'end_time' => 'nullable|date_format:Y-m-d H:i:s',
            // 頁碼
            'page' => 'nullable|integer',
            // 每頁筆數
            'per_page' => 'nullable|integer',
        ];
    }
}
