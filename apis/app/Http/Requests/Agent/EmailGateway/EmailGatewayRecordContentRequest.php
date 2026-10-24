<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EmailGateway;

use App\Http\Requests\AbstractRequest;

class EmailGatewayRecordContentRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 發送紀錄ID
             *
             * @example 1
             */
            'send_id' => 'required|integer|min:1',
        ];
    }
}
