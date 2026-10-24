<?php

declare(strict_types=1);

namespace App\Http\Requests\Frontend\Withdrawal;

use App\Http\Requests\AbstractRequest;

class GetOrdersRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /** 頁碼 */
            'page' => 'nullable|integer',
            /** 每頁筆數 */
            'per_page' => 'nullable|integer',
        ];
    }
}
