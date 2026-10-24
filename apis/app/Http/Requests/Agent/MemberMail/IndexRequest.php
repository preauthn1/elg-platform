<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberMail;

use App\Http\Requests\AbstractRequest;

/**
 * 站內信列表 工單4152
 */
class IndexRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 發送類型 1:指定會員 2:會員群組 3:全平臺 4:代理及子代理（不帶為全部）
             *
             * @example 1
             */
            'send_type' => 'nullable|integer|in:1,2,3,4',
            /**
             * @var int 狀態 1:啟用 0:停用（不帶為全部）
             *
             * @example 1
             */
            'status' => 'nullable|integer|in:0,1',
            /**
             * @var string 標題關鍵字（比對任一語系的標題）
             *
             * @example "維護通知"
             */
            'keyword' => 'nullable|string|max:100',
            /**
             * @var int 每頁筆數
             *
             * @example 15
             */
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
