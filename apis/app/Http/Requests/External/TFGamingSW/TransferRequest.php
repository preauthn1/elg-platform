<?php

declare(strict_types=1);

namespace App\Http\Requests\External\TFGamingSW;

use App\Http\Requests\AbstractRequest;

class TransferRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'loginName' => 'required|string',
            'amount' => 'required|numeric',
            'placeBet' => 'required|boolean',
            'ticketNum' => 'required|string',
            'description' => 'required|string',

            'ticketDetail' => 'required|array',
            'ticketDetail.id' => 'required|string',
            'ticketDetail.date_created' => 'required|string',
            'ticketDetail.settlement_datetime' => 'string|nullable',
        ];
    }
}
