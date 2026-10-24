<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EventV2;

use App\Http\Requests\AbstractRequest;

class AllowJoinRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 拒絕參加活動ID
             *
             * @example 1
             */
            'id' => 'required|integer',
        ];
    }
}
