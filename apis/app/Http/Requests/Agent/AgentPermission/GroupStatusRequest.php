<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\AgentPermission;

use App\Http\Requests\AbstractRequest;

/**
 * 角色啟用／停用
 */
class GroupStatusRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            /**
             * @var int 0:停用 1:啟用
             */
            'status' => 'required|integer|in:0,1',
        ];
    }
}
