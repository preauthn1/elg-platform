<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\AgentPermission;

use App\Http\Requests\AbstractRequest;

/**
 * 代理的組別管理權
 */
class GroupPermissionViewRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'agent_id' => 'required|integer|min:1',
        ];
    }
}
