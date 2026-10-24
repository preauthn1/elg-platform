<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EmailGateway;

use App\Enums\StatusEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rule;

class EmailGatewayUpdateStatusRequest extends AbstractRequest
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
            /**
             * @var int 狀態(0:停用, 1:啟用)
             *
             * @example 1
             */
            'status' => ['required', 'integer', Rule::enum(StatusEnum::class)],
        ];
    }
}
