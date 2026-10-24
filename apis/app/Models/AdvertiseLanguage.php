<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $advertise_id 廣告ID(advertises.id)
 * @property int $language_id 語言ID, 0:全語系, 1~N:參考languages.id
 * @property int $advertise_image_id 圖片ID(advertise_images.id)
 * @property int $advertise_image_pc_id PC圖片ID(advertise_images.id)
 * @property string|null $title 廣告標題
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作人員
 * @property-read Collection<int, AdvertiseImage> $advertiseImage
 * @property-read int|null $advertise_image_count
 * @property-read Collection<int, AdvertiseImage> $advertiseImagePc
 * @property-read int|null $advertise_image_pc_count
 *
 * @method static Builder<static>|AdvertiseLanguage newModelQuery()
 * @method static Builder<static>|AdvertiseLanguage newQuery()
 * @method static Builder<static>|AdvertiseLanguage query()
 *
 * @mixin \Eloquent
 */
class AdvertiseLanguage extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function advertiseImage()
    {
        return $this->hasMany(AdvertiseImage::class, 'id', 'advertise_image_id');
    }

    public function advertiseImagePc()
    {
        return $this->hasMany(AdvertiseImage::class, 'id', 'advertise_image_pc_id');
    }
}
