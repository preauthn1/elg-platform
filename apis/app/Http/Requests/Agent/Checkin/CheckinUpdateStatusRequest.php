<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Checkin;

use App\Http\Requests\AbstractRequest;

class CheckinUpdateStatusRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 打卡活動ID
             *
             * @example 16
             */
            'id' => 'required|integer',
            /**
             * @var int 狀態
             *
             * @example 1
             */
            'status' => 'required|integer|in:0,1',
        ];
    }
}
