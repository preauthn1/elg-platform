<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $summary_time 時間(日)
 * @property int $rebate_type 類別 1. 投注額 2. 輸分
 * @property int $reward_type 1:真金 2:禮碼
 * @property int $agent_id 代理系統編號
 * @property int $member_id 會員系統編號
 * @property int $from_member_id 獎勵來源
 * @property int $vendor_id 遊戲系統編號
 * @property int $game_type_id 遊戲類別系統編號
 * @property numeric $bet 投注金額-自己
 * @property numeric $down_bet 投注金額-下層
 * @property numeric $down2_bet 投注金額-下下層
 * @property numeric $rebate 投注反水-自己
 * @property numeric $down_rebate 投注反水-下層
 * @property numeric $down2_rebate 投注反水-下下層
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Member|null $fromMember
 * @property-read GameType|null $gameType
 * @property-read Member|null $member
 * @property-read Vendor|null $vendor
 *
 * @method static Builder<static>|ThreeHierarchyBonusReport newModelQuery()
 * @method static Builder<static>|ThreeHierarchyBonusReport newQuery()
 * @method static Builder<static>|ThreeHierarchyBonusReport query()
 *
 * @mixin \Eloquent
 */
class ThreeHierarchyBonusReport extends Model
{
    protected $table = 'three_hierarchy_bonus_report';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id', 'id');
    }

    public function fromMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'from_member_id', 'id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'id');
    }

    public function gameType(): BelongsTo
    {
        return $this->belongsTo(GameType::class, 'game_type_id', 'id');
    }
}
