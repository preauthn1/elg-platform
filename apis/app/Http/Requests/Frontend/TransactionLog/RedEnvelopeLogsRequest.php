<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\TransactionLog;

use App\Http\Requests\AbstractRequest;

class RedEnvelopeLogsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            // 類型
            'type' => 'nullable|integer||in:1,2,3',
            // 頁碼
            'page' => 'required|integer',
            // 每頁筆數
            'per_page' => 'required|integer',
        ];
    }
}
