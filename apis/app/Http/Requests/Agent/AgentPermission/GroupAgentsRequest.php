<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\AgentPermission;

use App\Http\Requests\AbstractRequest;

/**
 * 角色底下的代理
 */
class GroupAgentsRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer',
        ];
    }
}
