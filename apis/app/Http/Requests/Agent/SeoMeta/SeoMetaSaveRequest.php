<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\SeoMeta;

use App\Enums\Agent\SeoPageEnum;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rule;

/**
 * SEO 元數據－儲存 工單4102
 *
 * 一次儲存單一域名的所有頁面；is_default 為域名層級旗標，設為 true 會取消同線其他域名的預設。
 */
class SeoMetaSaveRequest extends AbstractRequest
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
            'is_default' => ['required', 'boolean'],

            'items' => ['present', 'array'],
            'items.*.page' => ['required', Rule::enum(SeoPageEnum::class)],
            'items.*.title' => ['nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.keywords' => ['nullable', 'string', 'max:500'],
        ];
    }
}
