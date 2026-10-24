<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\General\ContextKeyEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

/**
 * @property int $id
 * @property int $agent_id 代理ID(agents.id)
 * @property int $exclude_agent_id 排除代理ID
 * @property string $start_time 開始時間
 * @property string $end_time 結束時間
 * @property int $tag 系統標題類別 1:熱門,2:活動,3:更新,4:遊戲,5:維護
 * @property int $type 連結類型; 0:無連結, 1:公告彈窗, 2:站外連結 3:站內連結
 * @property int $receiver_type 公告類別;1:會員前台2:代理後台
 * @property string|null $title 公告標題
 * @property string|null $content 連結類型內容
 * @property int $platform_announcement_image_id 圖片ID(platform_announcement_image.id)
 * @property int $open_window 另開視窗; 0:不另開(h5:該頁面, app:webview), 1:另開(h5:分頁, app:browser)
 * @property int $sort 排序
 * @property int $status 狀態; 0:停用, 1:啟用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作人員
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Agent> $excludeAgents
 * @property-read int|null $exclude_agents_count
 * @property-read PlatformAnnouncementImage|null $image
 * @property-read mixed $image_url
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Agent> $includeAgents
 * @property-read int|null $include_agents_count
 * @property-read PlatformAnnouncementLanguage|null $language
 *
 * @method static \Database\Factories\PlatformAnnouncementFactory factory($count = null, $state = [])
 * @method static Builder<static>|PlatformAnnouncement newModelQuery()
 * @method static Builder<static>|PlatformAnnouncement newQuery()
 * @method static Builder<static>|PlatformAnnouncement query()
 *
 * @mixin \Eloquent
 */
class PlatformAnnouncement extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function language(): HasOne
    {
        $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);

        return $this->hasOne(PlatformAnnouncementLanguage::class)
            ->where('language_id', $languageId);
    }

    public function image(): HasOne
    {
        return $this->hasOne(PlatformAnnouncementImage::class, 'id', 'platform_announcement_image_id');
    }

    protected function title(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($this->language) {
                    return $this->language->title ?? $value;
                }

                return $value;
            }
        );
    }

    protected function content(): Attribute
    {
        return Attribute::get(function ($value) {
            return $this->language?->content ? $this->language->content : $value;
        });
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(function () {
            return $this->language->image_url ?? $this->image->image_url ?? '';
        });
    }

    public function includeAgents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'platform_announcement_include_agents', 'platform_announcement_id', 'agent_id');
    }

    public function excludeAgents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'platform_announcement_exclude_agents', 'platform_announcement_id', 'agent_id');
    }
}
