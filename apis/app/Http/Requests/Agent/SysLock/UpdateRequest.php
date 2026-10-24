<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\SysLock;

use App\Http\Requests\AbstractRequest;

class UpdateRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            'agent_id' => 'required|integer|exists:agents,id',
            'sys_lock' => 'required|in:0,1',
            'remark' => 'nullable|string|max:500',
        ];
    }
}
