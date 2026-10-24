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
 * @property int $id 流水號
 * @property int|null $member_id 玩家編號
 * @property int|null $parent_id 上層代理系統編號(User)
 * @property int|null $topper_member_id 上層玩家編號。紀錄上層玩家編號，如果該欄位為null代表為最上層
 * @property int|null $agent_mode 代理模式類型(legacy,實際生效走群組設定7001)。0:無,1:無限代,2:三層代理(計算含代理),3:三層代理(計算限定會員),4:winbox(佔位),5:推廣代理GGR
 * @property int|null $agent_level 會員層級
 * @property string|null $agent_relation 代理鏈結，格式:id_id_id(包含自己)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Agent|null $agent
 * @property-read Member|null $member
 * @property-read Member|null $parentMember
 * @property-read Collection<int, SummaryBetRecord> $summaryBetRecords
 * @property-read int|null $summary_bet_records_count
 * @property-read Member|null $topParentMember
 *
 * @method static Builder<static>|MemberAgent newModelQuery()
 * @method static Builder<static>|MemberAgent newQuery()
 * @method static Builder<static>|MemberAgent query()
 * @method static Builder<static>|MemberAgent sameHierarchy(\App\Models\Member $member)
 *
 * @mixin \Eloquent
 */
class MemberAgent extends Model
{
    protected $table = 'member_agent';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function parentMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'topper_member_id');
    }

    public function topParentMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'parent_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function summaryBetRecords(): HasMany
    {
        return $this->hasMany(SummaryBetRecord::class, 'member_id', 'member_id');
    }

    public function scopeSameHierarchy($query, Member $member)
    {
        return $query->where('parent_id', $member->parent)
            ->where('agent_relation', 'like', "%,{$member->id},%");
    }
}
