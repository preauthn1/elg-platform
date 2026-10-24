<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\DomainManage;

use App\Http\Requests\AbstractRequest;

class IndexRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 域名關鍵字（模糊）
             *
             * @example "agent-backend"
             */
            'domain' => 'nullable|string|max:191',
            /**
             * @var int 類型編號（domain_types.id）
             *
             * @example 1
             */
            'domain_type_id' => 'nullable|integer',
            /**
             * @var string 名稱關鍵字（模糊）
             *
             * @example "代理後台"
             */
            'domain_name' => 'nullable|string|max:191',
            /**
             * @var int 狀態 1:啟用 0:停用
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
