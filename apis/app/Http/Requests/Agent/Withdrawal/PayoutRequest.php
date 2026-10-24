<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Withdrawal;

use App\Http\Requests\AbstractRequest;

class PayoutRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'agent_cash_flow_id' => 'required|integer',
            'order_id' => 'required|integer',
        ];
    }
}
