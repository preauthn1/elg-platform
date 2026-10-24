<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\AgentPermission;

use App\Http\Requests\AbstractRequest;

/**
 * 更新代理的組別管理權（整包：沒列到的組別關閉）
 */
class GroupPermissionUpdateRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'agent_id' => 'required|integer|min:1',
            /**
             * @var array<int> 有權的設定組別 id
             */
            'group_permission' => 'present|array',
            'group_permission.*' => 'required|integer|min:1',
        ];
    }
}
