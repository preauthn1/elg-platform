<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\TransactionLog;

use App\Http\Requests\AbstractRequest;

class SearchRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            // 類型
            'type' => 'nullable|integer',
            // 開始時間
            'start_time' => 'required|date',
            // 結束時間
            'end_time' => 'required|date',
            // 頁碼
            'page' => 'required|integer',
            // 每頁筆數
            'per_page' => 'required|integer',
        ];
    }
}
