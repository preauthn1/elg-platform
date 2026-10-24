<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\EventWJackpot;

use App\Http\Requests\AbstractRequest;

class IssueTicketsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 抽獎活動期數ID
             *
             * @example 1
             */
            'issue_id' => 'required|integer',
            /**
             * 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer|min:1',
            /**
             * 每頁顯示數量
             *
             * @example 20
             */
            'per_page' => 'required|integer|min:1',
        ];
    }
}
