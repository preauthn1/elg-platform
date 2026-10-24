<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id 流水號
 * @property string $summary_time 時間(日)
 * @property int $member_id 會員系統編號
 * @property int $vendor_id 遊戲商系統編號
 * @property string|null $game_id 遊戲系統編號
 * @property numeric $bet 投注金額-自己
 * @property numeric $bet_direct 投注金額-直屬
 * @property numeric $bet_team 投注金額-團隊
 * @property numeric $win_lose 贏得金額-自己
 * @property numeric $win_lose_direct 贏得金額-直屬
 * @property numeric $win_lose_team 贏得金額-團隊
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $admin 更新者ID
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AgentVendor> $agentVendors
 * @property-read int|null $agent_vendors_count
 * @property-read Member|null $member
 * @property-read Vendor|null $vendor
 * @property-read \Illuminate\Database\Eloquent\Collection<int, VendorInfoGame> $vendorInfoGames
 * @property-read int|null $vendor_info_games_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SummaryBetRecordTeam newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SummaryBetRecordTeam newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SummaryBetRecordTeam query()
 *
 * @mixin \Eloquent
 */
class SummaryBetRecordTeam extends Model
{
    //
    protected $table = 'summary_bet_records_team';

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function agentVendors(): HasMany
    {
        return $this->hasMany(AgentVendor::class, 'vendor_id', 'vendor_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function vendorInfoGames(): HasMany
    {
        return $this->hasMany(VendorInfoGame::class, 'game_id', 'game_id');
    }
}
