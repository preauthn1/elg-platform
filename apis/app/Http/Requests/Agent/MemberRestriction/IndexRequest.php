<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberRestriction;

use App\Http\Requests\AbstractRequest;

class IndexRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 目標名稱關鍵字（會員帳號或群組名稱）
             *
             * @example "demo"
             */
            'username' => 'nullable|string',
            /**
             * @var string 備註關鍵字
             *
             * @example "test"
             */
            'remark' => 'nullable|string',
            /**
             * @var int 規則狀態 1:啟用 0:關閉
             *
             * @example 1
             */
            'status' => 'nullable|in:0,1',
            /**
             * @var int 限制類型 1:会员管理 2:会员群组
             *
             * @example 1
             */
            'type' => 'nullable|in:1,2',
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
