<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EventV2;

use App\Http\Requests\AbstractRequest;

class QuitRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 參加活動ID
             *
             * @example 1
             */
            'join_id' => 'required|integer',
        ];
    }
}
