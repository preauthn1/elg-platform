<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\SocialLink;

use App\Http\Requests\AbstractRequest;
use App\Models\AgentSocialLink;

/**
 * 社群入口配置－整批儲存 工單4106
 *
 * 前端送完整清單（含既有列的 id），後端以差集處理新增／更新／刪除。
 */
class SocialLinkSaveRequest extends AbstractRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 僅 super admin 有效；其餘代理一律取自己歸屬的總代
            'agent_id' => ['nullable', 'integer', 'min:1'],

            'items' => ['present', 'array', 'max:' . AgentSocialLink::MAX_PER_AGENT],
            'items.*.id' => ['nullable', 'integer', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:50'],
            'items.*.link_type' => ['required', 'integer', 'in:1,2'],
            'items.*.link_url' => ['nullable', 'string', 'max:500'],
            'items.*.icon_url' => ['nullable', 'string', 'max:500'],
            'items.*.sort' => ['nullable', 'integer', 'min:0'],
            'items.*.status' => ['required', 'integer', 'in:0,1'],
        ];
    }
}
