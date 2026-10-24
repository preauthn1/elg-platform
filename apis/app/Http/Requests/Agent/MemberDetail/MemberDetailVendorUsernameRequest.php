<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberDetail;

use App\Http\Requests\AbstractRequest;

class MemberDetailVendorUsernameRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 會員系統編號
             *
             * @example 12345
             */
            'member_id' => 'required|integer|min:1',
            /**
             * @var int 遊戲商系統編號
             *
             * @example 61
             */
            'vendor_id' => 'required|integer|min:1',
        ];
    }
}
