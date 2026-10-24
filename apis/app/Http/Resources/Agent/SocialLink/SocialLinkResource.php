<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\SocialLink;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 社群入口配置 工單4106
 *
 * @mixin \App\Models\AgentSocialLink
 */
class SocialLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 系統編號
             *
             * @example 1
             */
            'id' => (int) $this->id,
            /**
             * @var string 顯示名稱
             *
             * @example "Telegram"
             */
            'name' => $this->name,
            /**
             * @var int 連結類型 1:網址(點擊跳轉) 2:檔案(點擊下載)
             *
             * @example 1
             */
            'link_type' => (int) $this->link_type,
            /**
             * @var null|string 連結網址，或上傳後的檔案 URL
             *
             * @example "https://example.invalid/example"
             */
            'link_url' => $this->link_url,
            /**
             * @var null|string 前台顯示的 icon 圖片 URL
             *
             * @example "https://example.invalid/bucket/social_links/tg.png"
             */
            'icon_url' => $this->icon_url,
            /**
             * @var int 顯示順序，小的在前
             *
             * @example 1
             */
            'sort' => (int) $this->sort,
            /**
             * @var int 狀態 0:關閉 1:啟用
             *
             * @example 1
             */
            'status' => (int) $this->status,
        ];
    }
}
