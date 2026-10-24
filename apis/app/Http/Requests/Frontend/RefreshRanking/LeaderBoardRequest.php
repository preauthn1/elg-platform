<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\RefreshRanking;

use App\Http\Requests\AbstractRequest;

class LeaderBoardRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 活動編號
             *
             * @example 1
             */
            'event_id' => 'required|integer|min:1',
            /**
             * 期數
             *
             * @example 1
             */
            'no' => 'required|integer|min:1',
            /**
             * 頁碼（未帶時為 1）
             *
             * @example 1
             */
            'page' => 'nullable|integer|min:1',
            /**
             * 每頁筆數（未帶時取獎項總數）
             *
             * @example 10
             */
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}
