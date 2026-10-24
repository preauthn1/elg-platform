<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_group_id agents.customized_setting_group_id
 * @property int $type 圖片連結類型; 0:無連結, 1:連結至遊戲類型, 2:連結至遊戲, 3.連結至遊戲商, 4:外部連結, 5:內部連結
 * @property string $content
 * @property int $open_window 是否另開視窗
 * @property int $sort 排序
 * @property int $status 狀態; 0:關閉, 1:開啟
 * @property string|null $description 顯示給前端的多語系
 * @property string|null $remark 備註
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作者
 * @property-read Game|null $game
 * @property-read Collection<int, InformationLanguage> $languages
 * @property-read int|null $languages_count
 *
 * @method static Builder<static>|Information newModelQuery()
 * @method static Builder<static>|Information newQuery()
 * @method static Builder<static>|Information query()
 *
 * @mixin \Eloquent
 */
class Information extends Model
{
    protected $table = 'informations';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function languages(): HasMany
    {
        return $this->hasMany(InformationLanguage::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'content', 'id');
    }
}
