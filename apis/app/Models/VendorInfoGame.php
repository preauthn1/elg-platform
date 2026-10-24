<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vendor_info_id 商戶資料ID
 * @property int $game_id 遊戲ID
 * @property int $join_bet_event 是否可參加投注額活動(0:不可，1:可以)
 * @property int $join_win_event 是否可參加贏分活動(0:不可，1:可以)
 * @property int $is_new 是否為新遊戲(0:否,1:是)
 * @property int $is_hot 是否為熱門遊戲(0:否,1:是)
 * @property int $sort 排序
 * @property numeric $valid_bet_rate 有效投注轉換比例
 * @property int|null $calc_flow_limit 是否計算稽核流水
 * @property int $status 狀態(0停用，1啟用)
 * @property int $bettable 遊戲是否可投注(0:否,1:是)
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read Game|null $game
 * @property-read VendorInfo|null $vendorInfo
 *
 * @method static Builder<static>|VendorInfoGame newModelQuery()
 * @method static Builder<static>|VendorInfoGame newQuery()
 * @method static Builder<static>|VendorInfoGame query()
 *
 * @mixin \Eloquent
 */
class VendorInfoGame extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function vendorInfo(): BelongsTo
    {
        return $this->belongsTo(VendorInfo::class);
    }
}
