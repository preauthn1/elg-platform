<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Prize;

use App\Http\Requests\AbstractRequest;

class DrawPrizeRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 活動編號
             *
             * @example 1
             */
            'event_id' => 'required|integer|min:1',
        ];
    }
}
