<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Mission;

use App\Http\Requests\AbstractRequest;

class ReceiveRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 任務ID
             *
             * @example 1
             */
            'mission_id' => 'required|integer',
        ];
    }
}
