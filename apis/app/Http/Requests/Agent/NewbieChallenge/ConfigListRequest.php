<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\NewbieChallenge;

use App\Http\Requests\AbstractRequest;

class ConfigListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string|null 代理帳號（精確比對）
             *
             * @example "vwagent001"
             */
            'agent_username' => 'nullable|string|max:50',
            /**
             * @var int|null 狀態; 0:停用, 1:啟用
             *
             * @example 1
             */
            'status' => 'nullable|integer|in:0,1',
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer|min:1',
            /**
             * @var int 每頁筆數
             *
             * @example 15
             */
            'per_page' => 'required|integer|min:1',
        ];
    }
}
