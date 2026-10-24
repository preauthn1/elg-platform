<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Deposit;

use App\Http\Requests\AbstractRequest;

class TopUpChannelsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'order_type_id' => 'required|integer',
        ];
    }
}
