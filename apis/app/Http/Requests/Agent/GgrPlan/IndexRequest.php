<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GgrPlan;

use App\Http\Requests\AbstractRequest;

class IndexRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string GGR標題關鍵字
             *
             * @example "test"
             */
            'name' => 'nullable|string',
            /**
             * @var int 狀態 1:啟用 0:停用
             *
             * @example 1
             */
            'status' => 'nullable|in:0,1',
            /**
             * @var int 結算週期 1:每日 2:每週 3:每月
             *
             * @example 1
             */
            'settlement_cycle' => 'nullable|in:1,2,3',
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
