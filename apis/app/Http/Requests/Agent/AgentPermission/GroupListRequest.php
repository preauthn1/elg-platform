<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\AgentPermission;

use App\Http\Requests\AbstractRequest;

/**
 * 角色列表（target/tickets#4422）。per_page 超出 1〜100 時照 API_Agent 退回 25，不報錯
 */
class GroupListRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string|null 角色名稱（模糊）
             */
            'name' => 'nullable|string|max:50',
            /**
             * @var int|null 狀態 0:停用 1:啟用
             */
            'status' => 'nullable|integer|in:0,1',
            /**
             * @var int|null 所屬代理：super admin 任選（0＝只看 admin 持有，1＝admin預設角色）；總代在自己＋下線建的範圍內篩；一般代理只有自己
             */
            'owner_agent_id' => 'nullable|integer|min:0',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer',
        ];
    }
}
