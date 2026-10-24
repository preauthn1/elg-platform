<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 推廣代理 GGR 條件設置階梯列 工單4124
 *
 * 一列＝一個階梯，結算取「合格會員數 ≥ user_count」的最高階 percentage。
 * 四項金額門檻是單一會員各自要達的值（非代理線加總），0＝該項無要求。
 * user_count 與 percentage 逐階嚴格遞增（Request withValidator 驗證）。
 *
 * @property int $id 流水號(不對外引用,update全量替換)
 * @property int $condition_id ggr_conditions.id
 * @property int $tier 階序(1-based)
 * @property int $user_count 用戶人數(必要條件,逐階遞增)
 * @property string $deposit_amount 存款門檻(單一會員,0=無要求)
 * @property string $total_bet_amount 總投注金額門檻(單一會員,0=無要求)
 * @property string $game_revenue_amount 遊戲總收入門檻(單一會員,0=無要求)
 * @property string $valid_bet_amount 有效流水門檻(單一會員,0=無要求)
 * @property string $percentage 應得百分比%(逐階遞增)
 * @property int $admin 更新者ID
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|GgrConditionTier newModelQuery()
 * @method static Builder<static>|GgrConditionTier newQuery()
 * @method static Builder<static>|GgrConditionTier query()
 *
 * @mixin \Eloquent
 */
class GgrConditionTier extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /** 所屬條件設置主表 */
    public function condition(): BelongsTo
    {
        return $this->belongsTo(GgrCondition::class, 'condition_id');
    }
}
