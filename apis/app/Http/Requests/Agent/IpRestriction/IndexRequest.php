<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\IpRestriction;

use App\Http\Requests\AbstractRequest;

class IndexRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string IP 關鍵字
             *
             * @example "1.2.3"
             */
            'ip' => 'nullable|string',
            /**
             * @var int 規則狀態 1:啟用 0:關閉
             *
             * @example 1
             */
            'status' => 'nullable|in:0,1',
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
