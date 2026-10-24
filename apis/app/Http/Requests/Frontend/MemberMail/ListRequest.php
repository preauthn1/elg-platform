<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\MemberMail;

use App\Http\Requests\AbstractRequest;

/**
 * 站內信列表（前台） 工單4152
 */
class ListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 頁碼
             *
             * @example 1
             */
            'page' => 'nullable|integer|min:1',
            /**
             * @var int 每頁筆數
             *
             * @example 15
             */
            'per_page' => 'nullable|integer|min:1|max:50',
        ];
    }
}
