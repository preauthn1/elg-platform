<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\SeoMeta;

use App\Http\Requests\AbstractRequest;

/**
 * SEO 元數據－清單 工單4102
 */
class SeoMetaListRequest extends AbstractRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 僅 super admin 有效；其餘代理一律取自己歸屬的總代，不採信此值
            'agent_id' => ['nullable', 'integer', 'min:1'],
            // 須屬於上述總代名下，後端另行驗證
            'agent_domain_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
