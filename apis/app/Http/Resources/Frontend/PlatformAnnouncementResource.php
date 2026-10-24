<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend;

use App\Models\PlatformAnnouncement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin PlatformAnnouncement */
class PlatformAnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // 'pal_id' => $this->pal_id,
            'pal_id' => $this->language->id ?? null,
            'sort' => $this->sort,
            'tag' => $this->tag, // 系統標題類別; 1:熱門, 2:活動, 3:更新, 4:遊戲, 5:維護
            'type' => $this->type, // 連結類型; 0:無連結, 1:公告彈窗, 2:站外連結 3:站內連結
            'title' => $this->title,
            'content' => $this->content,
            'open_window' => $this->open_window, // 另開視窗; 0:不另開(h5:該頁面, app:webview), 1:另開(h5:分頁, app:browser)
            'image_url' => $this->image_url,
            'display_time' => Carbon::parse($this->start_time)->toFrontendTime()->toDateTimeString(),
            'created_at' => $this->created_at->toFrontendTime()->toDateTimeString(),
        ];
    }
}
