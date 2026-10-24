<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\EventLuckyDraw;

use App\Http\Requests\AbstractRequest;

class ReceiveRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 抽獎券ID
             *
             * @example 1
             */
            'ticket_id' => 'required|integer',
        ];
    }
}
