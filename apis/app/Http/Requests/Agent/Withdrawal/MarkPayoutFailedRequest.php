<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Withdrawal;

use App\Http\Requests\AbstractRequest;

class MarkPayoutFailedRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'order_id' => 'required|integer',
            // 後台操作備註，寫入訂單 memo（比照舊 reviewOrder 慣例）
            'remark' => 'nullable|string|max:500',
        ];
    }
}
