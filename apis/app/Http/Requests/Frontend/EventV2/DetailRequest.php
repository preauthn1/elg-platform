<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\EventV2;

use App\Http\Requests\AbstractRequest;

class DetailRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 活動詳細ID
             *
             * @example 1
             */
            'event_detail_id' => 'required|integer',
        ];
    }
}
