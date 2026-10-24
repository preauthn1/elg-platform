<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Vip;

use App\Http\Requests\AbstractRequest;

class ReceiveRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 待領取獎項編號（member_vip_prizes.id）
             *
             * @example 1
             */
            'id' => 'required|integer|min:1',
        ];
    }
}
