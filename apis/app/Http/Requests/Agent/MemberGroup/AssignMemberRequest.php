<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberGroup;

use App\Http\Requests\AbstractRequest;

class AssignMemberRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 會員編號
             *
             * @example 100
             */
            'member_id' => 'required|integer',
            /**
             * @var int 會員群組編號(空/0=取消指派)
             *
             * @example 1
             */
            'member_group_id' => 'nullable|integer',
        ];
    }
}
