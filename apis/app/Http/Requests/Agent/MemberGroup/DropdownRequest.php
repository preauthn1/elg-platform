<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberGroup;

use App\Http\Requests\AbstractRequest;

class DropdownRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 會員編號(帶入時回該會員直屬代理的群組)
             *
             * @example 100
             */
            'member_id' => 'nullable|integer',
        ];
    }
}
