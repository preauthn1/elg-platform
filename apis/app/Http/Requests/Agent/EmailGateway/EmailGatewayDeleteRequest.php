<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EmailGateway;

use App\Http\Requests\AbstractRequest;

class EmailGatewayDeleteRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 電子郵件提供商ID
             *
             * @example 1
             */
            'id' => 'required|integer',
        ];
    }
}
