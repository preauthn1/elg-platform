<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\AgentPermission;

use App\Http\Requests\AbstractRequest;

/**
 * 角色 id（檢視）
 */
class GroupIdRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 角色 id
             */
            'id' => 'required|integer|min:1',
        ];
    }
}
