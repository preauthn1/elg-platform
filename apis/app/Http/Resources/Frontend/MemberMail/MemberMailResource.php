<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\MemberMail;

use App\Enums\LanguageEnum;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Context;

/**
 * 站內信（前台） 工單4152
 *
 * 內容取當前語系、未填 fallback 英文（英文於後台為必填，必有內容）。
 * languages 關聯已於 Service 限縮為「當前語系＋英文」兩筆內載入。
 */
class MemberMailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $languageId = (int) (Context::getHidden(\App\Enums\General\ContextKeyEnum::LANGUAGE_ID->value) ?? LanguageEnum::EN->value);

        $content = $this->languages->firstWhere('language_id', $languageId)
            ?? $this->languages->firstWhere('language_id', LanguageEnum::EN->value);

        return [
            /** @var int 站內信編號 */
            'id' => (int) $this->id,
            /** @var string 標題（當前語系，未填則為英文） */
            'title' => (string) ($content?->title ?? ''),
            /** @var string 內容（當前語系，未填則為英文） */
            'content' => (string) ($content?->content ?? ''),
            /** @var string 發布時間（前端時區） */
            'publish_time' => Carbon::parse($this->publish_time)->toFrontendTime()->format('Y-m-d H:i:s'),
            /** @var int 是否已讀 1:已讀 0:未讀 */
            'is_read' => (int) ($this->is_read ?? 0),
        ];
    }
}
