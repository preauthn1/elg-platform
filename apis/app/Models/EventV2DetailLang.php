<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $event_v2_detail_id 活動詳細ID
 * @property int $language_id 語系iD
 * @property string $name 活動名稱
 * @property string|null $image 活動圖片
 * @property string|null $reward_name 獎勵名稱
 * @property string|null $content 預設顯示活動內容
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Language|null $language
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2DetailLang newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2DetailLang newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2DetailLang query()
 *
 * @mixin \Eloquent
 */
class EventV2DetailLang extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function language(): HasOne
    {
        return $this->hasOne(Language::class, 'id', 'language_id');
    }
}
