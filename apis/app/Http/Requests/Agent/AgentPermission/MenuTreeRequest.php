<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\AgentPermission;

use App\Http\Requests\AbstractRequest;

/**
 * 代理選單樹。is_exclude_tag_invisible 照收不用（隱藏由前端做，與 API_Agent 一致）
 */
class MenuTreeRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'agent_id' => 'required|integer|min:1',
            'is_exclude_tag_invisible' => 'nullable',
        ];
    }
}
