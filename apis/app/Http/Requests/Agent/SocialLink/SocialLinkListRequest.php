<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\SocialLink;

use App\Http\Requests\AbstractRequest;

/**
 * 社群入口配置－清單 工單4106
 */
class SocialLinkListRequest extends AbstractRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 僅 super admin 有效；其餘代理一律取自己歸屬的總代，不採信此值
            'agent_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
