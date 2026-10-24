<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GgrCondition;

use App\Http\Requests\AbstractRequest;

class IndexRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 條件標題關鍵字
             *
             * @example "60% Winloss"
             */
            'name' => 'nullable|string',
            /**
             * @var int 狀態 1:啟用 0:停用
             *
             * @example 1
             */
            'status' => 'nullable|in:0,1',
            /**
             * @var int 類型 1:或者(OR) 2:和(AND)
             *
             * @example 1
             */
            'logic_type' => 'nullable|in:1,2',
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
