<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventV2\EventV2PeriodEnum;
use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $event_v2_id 活動ID
 * @property int $agent_id 代理ID
 * @property string|null $condition_formula 領獎條件公式，例如 deposit >= 20 或 20 <= deposit <= 49.99
 * @property array<array-key, mixed>|null $condition_config 領獎條件客製化設定
 * @property string|null $reward_formula 活動獎勵計算公式，例如 "deposit * 0.5"
 * @property array<array-key, mixed>|null $reward_config 活動獎勵計算客製化設定
 * @property string|null $balance_formula 餘額限制的動態公式，例如 "(deposit + bonus) * 3"
 * @property array<array-key, mixed>|null $balance_config 餘額限制客製化設定
 * @property string|null $flow_formula 流水限制的動態公式，例如 "(deposit + bonus) * 3"
 * @property array<array-key, mixed>|null $flow_config 流水限制客製化設定
 * @property string|null $withdraw_formula 提款限額的動態公式，例如 "bonus * 3"
 * @property array<array-key, mixed>|null $withdraw_config 提款限額客製化設定
 * @property int|null $bonus_vendor_id 活動序號vendor id
 * @property string|null $redirect_path 參加活動後導向的路徑
 * @property string|null $ip_block
 * @property string|null $phone_block
 * @property string|null $account_block
 * @property int $join_withdraw 是否為參加即領獎
 * @property int $cost_type 成本類型，說明請參考 EventV2DetailCostTypeEnum
 * @property EventV2WithdrawTypeEnum $withdraw_type 領取去向：1=主錢包（預設）2=積分（target/tickets#4231）
 * @property EventV2PeriodEnum $member_period 會員週期（可重複參加）：每人次數／金額的累計區間、不勾參加即領獎時的作廢週期；0=不分 1=日 2=週 3=月 4=年，只有紅包用（target/tickets#4234）
 * @property EventV2PeriodEnum $total_period 全站週期（包括期間）：max_join／max_amount 的累計區間；值域同上，只有紅包用（target/tickets#4234）
 * @property string|null $start_time 活動開始時間
 * @property string|null $end_time 活動結束時間
 * @property int $sort 排序
 * @property bool $is_recycle 是否回收
 * @property int $status 是否啟用該活動
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Agent> $detailExcludeAgents
 * @property-read int|null $detail_exclude_agents_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Vendor> $detailExcludeVendors
 * @property-read int|null $detail_exclude_vendors_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, GameType> $detailGameTypes
 * @property-read int|null $detail_game_types_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventV2DetailLang> $detailLang
 * @property-read int|null $detail_lang_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Vendor> $detailVendors
 * @property-read int|null $detail_vendors_count
 * @property-read EventV2|null $eventV2
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventV2UserJoin> $eventV2UserJoins
 * @property-read int|null $event_v2_user_joins_count
 *
 * @method static \Database\Factories\EventV2DetailFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2Detail newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2Detail newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2Detail query()
 *
 * @mixin \Eloquent
 */
class EventV2Detail extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'condition_config' => 'array',
        'reward_config' => 'array',
        'balance_config' => 'array',
        'flow_config' => 'array',
        'withdraw_config' => 'array',
        'withdraw_type' => EventV2WithdrawTypeEnum::class,
        'member_period' => EventV2PeriodEnum::class,
        'total_period' => EventV2PeriodEnum::class,
    ];

    public function eventV2(): BelongsTo
    {
        return $this->belongsTo(EventV2::class, 'event_v2_id');
    }

    public function detailLang(): HasMany
    {
        return $this->hasMany(EventV2DetailLang::class, 'event_v2_detail_id');
    }

    /** 目標用戶（指定會員或會員群組，target/tickets#4234）；沒有任何一列＝不限制 */
    public function targets(): HasMany
    {
        return $this->hasMany(EventV2DetailTarget::class, 'event_v2_detail_id');
    }

    public function detailExcludeAgents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'event_v2_detail_exclude_agents', 'event_v2_detail_id', 'agent_id');
    }

    public function detailGameTypes(): BelongsToMany
    {
        return $this->belongsToMany(GameType::class, 'event_v2_detail_game_types', 'event_v2_detail_id', 'game_type_id');
    }

    public function detailVendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'event_v2_detail_vendors', 'event_v2_detail_id', 'vendor_id');
    }

    public function detailExcludeVendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'event_v2_detail_exclude_vendors', 'event_v2_detail_id', 'vendor_id');
    }

    public function eventV2UserJoins(): HasMany
    {
        return $this->hasMany(EventV2UserJoin::class);
    }
}
