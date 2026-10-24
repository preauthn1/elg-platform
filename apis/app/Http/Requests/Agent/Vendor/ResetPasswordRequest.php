<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Vendor;

use App\Http\Requests\AbstractRequest;

class ResetPasswordRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * 會員 ID
             */
            'member_id' => 'required|integer|min:1',
            /**
             * 遊戲商 ID
             */
            'vendor_id' => 'required|digits_between:1,10',
            /**
             * 遊戲帳號（同一 vendor_id 可能有多個遊戲帳號，需指定其一）
             */
            'vendor_username' => 'required|string',
        ];
    }
}
