<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Ranking;

use App\Http\Requests\AbstractRequest;

class DomainTypeRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 域名
             *
             * @example example.invalid
             */
            'domain' => 'required|string',
            /**
             * 排行區間；1:即時, 2:日排行, 3:週排行, 4:月排行
             *
             * @example 1
             */
            'type' => 'required|integer|in:1,2,3,4',
        ];
    }
}
