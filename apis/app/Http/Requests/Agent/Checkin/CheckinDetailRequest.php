<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Checkin;

use App\Http\Requests\AbstractRequest;

class CheckinDetailRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 打卡活動詳細ID
             *
             * @example 1
             */
            'id' => 'required|integer',
        ];
    }
}
