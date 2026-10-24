<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\EventWJackpot;

use App\Http\Requests\AbstractRequest;

class ReceiveAllRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 抽獎活動期數ID
             *
             * @example 1
             */
            'issue_id' => 'required|integer',
        ];
    }
}
