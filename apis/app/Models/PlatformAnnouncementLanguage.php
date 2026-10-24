<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $platform_announcement_id 平台ID
 * @property int $language_id 語言ID, 0:全語系, 1~N:參考languages.id
 * @property string|null $title 標題
 * @property string|null $content 內容
 * @property int $platform_announcement_image_id 圖片ID
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作人員
 * @property-read PlatformAnnouncementImage|null $image
 * @property-read mixed $image_url
 *
 * @method static \Database\Factories\PlatformAnnouncementLanguageFactory factory($count = null, $state = [])
 * @method static Builder<static>|PlatformAnnouncementLanguage newModelQuery()
 * @method static Builder<static>|PlatformAnnouncementLanguage newQuery()
 * @method static Builder<static>|PlatformAnnouncementLanguage query()
 *
 * @mixin \Eloquent
 */
class PlatformAnnouncementLanguage extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function image(): HasOne
    {
        return $this->hasOne(PlatformAnnouncementImage::class, 'id', 'platform_announcement_image_id');
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->image->image_url ?? ''
        );
    }
}
