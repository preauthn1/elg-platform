<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Event;

use App\Http\Requests\AbstractRequest;

class LegacyDetailRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 活動編號
             *
             * @example 1
             */
            'id' => 'required|integer|min:1',
        ];
    }
}
