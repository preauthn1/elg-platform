<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Withdrawal;

use App\Http\Requests\AbstractRequest;

class FillThirdPartyNoRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'order_id' => 'required|integer',
            // withdrawal_orders.third_party_order_no varchar(50)
            'third_party_order_no' => 'required|string|max:50',
            // 後台操作備註，寫入訂單 memo（比照舊 reviewOrder 慣例）
            'remark' => 'nullable|string|max:500',
        ];
    }
}
