<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\AgentPermission;

use App\Http\Requests\AbstractRequest;

/**
 * 新增角色。default_setting 為 sometimes：沒送＝不設預設
 */
class GroupCreateRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50',
            /**
             * @var int|null 所屬代理（持有者），不用送：誰建的就記誰（super admin 記 admin 持有、其他人記自己的主帳號）；送了不同的值回 9003
             */
            'owner_agent_id' => 'nullable|integer|min:0',
            'remark' => 'nullable|string|max:191',
            'status' => 'required|integer|in:0,1',
            'default_setting' => 'sometimes|integer|in:0,1',
            /**
             * @var array 勾選的選單 id 陣列，或 {"選單id": "action"}；可為空陣列
             */
            'menus' => 'present|array',
        ];
    }
}
