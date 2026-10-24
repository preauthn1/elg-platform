<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\AgentPermission;

use App\Http\Requests\AbstractRequest;

/**
 * 修改角色。所屬代理不可改：owner_agent_id 沒送＝沿用，送了必須等於原值否則 9003；default_setting 沒送＝沿用原值（送 0 才是取消預設）
 */
class GroupUpdateRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'name' => 'required|string|max:50',
            'owner_agent_id' => 'nullable|integer|min:0',
            'remark' => 'nullable|string|max:191',
            'status' => 'required|integer|in:0,1',
            'default_setting' => 'sometimes|integer|in:0,1',
            'menus' => 'present|array',
        ];
    }
}
