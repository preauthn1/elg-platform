<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Event;

use App\Http\Requests\AbstractRequest;

class LegacyListByDomainRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 域名
             *
             * @example example.invalid
             */
            'domain' => 'required|string|max:255',
            /**
             * 頁碼
             *
             * @example 1
             */
            'page' => 'required|integer|min:1',
            /**
             * 每頁顯示數量
             *
             * @example 30
             */
            'per_page' => 'required|integer|min:1',
        ];
    }
}
