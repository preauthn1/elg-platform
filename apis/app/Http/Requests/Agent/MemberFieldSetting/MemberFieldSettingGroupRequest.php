<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberFieldSetting;

use App\Http\Requests\AbstractRequest;

class MemberFieldSettingGroupRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 會員欄位設置組別ID(agent_groups.id, agent_group_type=6)
             *
             * @example 166
             */
            'group_id' => 'required|integer',
        ];
    }
}
