<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EmailGateway;

use App\Enums\StatusEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rule;

class EmailGatewayListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 電子郵件群組ID(agent_groups.id)
             *
             * @example 4249
             */
            'agent_group_id' => 'nullable|integer',
            /**
             * @var string 寄件信箱(前綴模糊查詢)
             *
             * @example "user@example.invalid"
             */
            'email' => 'nullable|string',
            /**
             * @var int 狀態(0:停用, 1:啟用)
             *
             * @example 1
             */
            'status' => ['nullable', 'integer', Rule::enum(StatusEnum::class)],
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer',
            /**
             * @var int 每頁筆數
             *
             * @example 20
             */
            'per_page' => 'required|integer',
        ];
    }
}
