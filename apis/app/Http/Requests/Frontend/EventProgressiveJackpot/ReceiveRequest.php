<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\EventProgressiveJackpot;

use App\Http\Requests\AbstractRequest;

class ReceiveRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 要領取的彩金等級
             *
             * @example 0
             */
            'level' => 'required|integer',
        ];
    }
}
