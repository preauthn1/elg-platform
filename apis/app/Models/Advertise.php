<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Advertise\AdvertiseTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $advertisement_path_id 版面位置id(advertisement_paths.id)
 * @property int $agent_id 代理ID(agents.id)
 * @property string $start_time 開始時間
 * @property string $end_time 結束時間
 * @property AdvertiseTypeEnum $type 圖片連結類型; 0:無連結, 1:連結至遊戲商, 2:連結至遊戲, 3.連結至遊戲類型, 8:內部連結, 9:外部連結
 * @property string|null $content 連結類型內容; 1:1(vendor_id), 2:1(game_id), 3:1(game_type_id), 8:depoist/unlimit, 9:http://example.invalid/
 * @property int $open_window 另開視窗; 0:不另開(h5:該頁面, app:webview), 1:另開(h5:分頁, app:browser)
 * @property int $sort 排序
 * @property int $status 狀態; 0:停用, 1:啟用
 * @property string|null $remark 備註
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作人員
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AdvertiseExcludeAgent> $excludeAgents
 * @property-read int|null $exclude_agents_count
 * @property-read Game|null $game
 *
 * @method static Builder<static>|Advertise newModelQuery()
 * @method static Builder<static>|Advertise newQuery()
 * @method static Builder<static>|Advertise query()
 *
 * @mixin \Eloquent
 */
class Advertise extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'type' => AdvertiseTypeEnum::class,
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'content', 'id');
    }

    public function excludeAgents(): HasMany
    {
        return $this->hasMany(AdvertiseExcludeAgent::class, 'advertise_id', 'id');
    }
}
