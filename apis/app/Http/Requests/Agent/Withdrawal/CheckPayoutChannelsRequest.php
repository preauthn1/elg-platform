<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Withdrawal;

use App\Http\Requests\AbstractRequest;

class CheckPayoutChannelsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'order_id' => 'required|integer',
        ];
    }
}
