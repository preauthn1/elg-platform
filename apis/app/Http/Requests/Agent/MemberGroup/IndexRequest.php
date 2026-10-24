<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberGroup;

use App\Http\Requests\AbstractRequest;

class IndexRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 群組名稱關鍵字
             *
             * @example "VIP"
             */
            'keyword' => 'nullable|string',
            /**
             * @var int 狀態 1:啟用 0:停用
             *
             * @example 1
             */
            'status' => 'nullable|in:0,1',
            /**
             * @var int|null 擁有者代理編號篩選
             *
             * @example 33893
             */
            'agent_id' => 'nullable|integer',
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
