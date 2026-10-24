<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend;

use App\Http\Requests\AbstractRequest;

class PaginationRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 每頁顯示數量
             *
             * @example 10
             */
            'per_page' => 'required|integer|min:1',
            /**
             * 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer|min:1',
        ];
    }
}
