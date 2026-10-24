<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\DomainManage;

use App\Enums\DomainTypeEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DomainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int 域名編號 */
            'id' => (int) $this->id,
            /** @var string 域名 */
            'domain' => $this->domain,
            /** @var int 類型編號 */
            'domain_type_id' => (int) $this->domain_type_id,
            /** @var string|null 類型名稱（中文） */
            'domain_type_name' => $this->whenLoaded(
                'type',
                fn () => $this->type?->memo ?: $this->type?->name
            ),
            /** @var string|null 名稱 */
            'domain_name' => $this->domain_name,
            /** @var string|null LOGO 淺色模式 */
            'logo_lm' => $this->logo_lm,
            /** @var string|null LOGO 深色模式（僅代理後台使用） */
            'logo_dm' => $this->logo_dm,
            /** @var int|null 所屬代理編號（一域名對一代理） */
            'agent_id' => $this->whenLoaded(
                'agents',
                fn () => ($id = $this->agents->first()?->agent_id) ? (int) $id : null
            ),
            /** @var string|null 所屬代理帳號 */
            'agent_username' => $this->whenLoaded(
                'agents',
                fn () => $this->agents->first()?->agent?->username
            ),
            /** @var string|null 網站 icon（favicon） */
            'icon' => $this->icon,
            /** @var string|null 色系（hex 色碼，僅客服後台使用） */
            'theme_color' => $this->theme_color,
            /** @var bool 該類型是否需要深色 LOGO */
            'needs_dark_logo' => DomainTypeEnum::tryFrom((int) $this->domain_type_id)?->needsDarkLogo() ?? false,
            /** @var bool 該類型是否需要色系 */
            'needs_theme_color' => DomainTypeEnum::tryFrom((int) $this->domain_type_id)?->needsThemeColor() ?? false,
            /** @var int 排序 */
            'sort' => (int) $this->sort,
            /** @var int 狀態 1:啟用 0:停用 */
            'status' => (int) $this->status,
        ];
    }
}
