<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\Member;

use App\Http\Requests\AbstractRequest;

class MemberKickRequest extends AbstractRequest
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
        ];
    }
}
