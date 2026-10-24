<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Jackpot;

use App\Http\Requests\AbstractRequest;

class ConfirmNotifyRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 中獎通知編號（event_jackpot_records.id）
             *
             * @example 1
             */
            'id' => 'required|integer|min:1',
        ];
    }
}
