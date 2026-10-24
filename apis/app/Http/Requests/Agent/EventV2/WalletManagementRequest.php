<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EventV2;

use App\Http\Requests\AbstractRequest;

class WalletManagementRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 代理帳號
             *
             * @example "frank"
             */
            'agent_username' => 'nullable|string',
            /**
             * @var string 會員帳號
             *
             * @example "daniel01"
             */
            'member_username' => 'nullable|string',
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer',
            /**
             * @var int 每頁筆數
             *
             * @example 10
             */
            'per_page' => 'required|integer',
        ];
    }
}
