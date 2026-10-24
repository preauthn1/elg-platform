<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\AgentPermission;

use App\Http\Requests\AbstractRequest;

/**
 * 存代理個人權限。menus 為 {"選單id": "action"}，空物件＝全部清空
 */
class SettingRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var int 目標代理 id
             */
            'agent_id' => 'required|integer|min:1',
            /**
             * @var array {"選單id": "action字串"}
             */
            'menus' => 'present|array',
        ];
    }
}
