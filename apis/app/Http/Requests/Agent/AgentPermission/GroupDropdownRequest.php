<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\AgentPermission;

use App\Http\Requests\AbstractRequest;

/**
 * 建立／編輯營運商的角色下拉。編輯帶 agent_id；新增帶 is_sub_account＋parent（決定目標是否為 super admin 子帳號）
 */
class GroupDropdownRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'agent_id' => 'nullable|integer|min:1',
            'is_sub_account' => 'nullable|integer|in:0,1',
            'parent' => 'nullable|integer|min:1',
        ];
    }
}
