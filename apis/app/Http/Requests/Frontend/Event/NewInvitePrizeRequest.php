<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Event;

use App\Http\Requests\AbstractRequest;

class NewInvitePrizeRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 獎項內容編號
             *
             * @example 1
             */
            'prize_content_id' => 'required|integer|min:1',
        ];
    }
}
