<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $agent_id 適用代理ID，null=全站
 * @property numeric $activity_amount 活動金
 * @property numeric $challenge_flow_multiple 挑戰流水倍數
 * @property numeric $bonus_flow_multiple 彩金流水倍數
 * @property numeric $min_unlock_amount 最低解鎖金額
 * @property numeric $max_unlock_amount 最高解鎖金額
 * @property int $bonus_window_hours 彩金有效期(小時)
 * @property array|null $allowed_vendor_ids 第二階段挑戰流水計算範圍的遊戲商/遊戲ID清單，與 excluded_vendor_ids 互斥；null=不限制遊戲商
 * @property array|null $allowed_game_type_ids 第二階段挑戰流水計算範圍的遊戲類型清單；null=不限制遊戲類型
 * @property array|null $excluded_vendor_ids 第二階段挑戰流水排除的遊戲商清單，與 allowed_vendor_ids 互斥；null=不排除
 * @property array|null $bonus_flow_vendor_ids 第四階段彩金流水計算範圍的遊戲商/遊戲ID清單，獨立於 allowed_vendor_ids，與 excluded_bonus_flow_vendor_ids 互斥；null=不限制遊戲商
 * @property array|null $bonus_flow_game_type_ids 第四階段彩金流水計算範圍的遊戲類型清單，獨立於 allowed_game_type_ids；null=不限制遊戲類型
 * @property array|null $excluded_bonus_flow_vendor_ids 第四階段彩金流水排除的遊戲商清單，與 bonus_flow_vendor_ids 互斥；null=不排除
 * @property int|null $ip_block 1=同IP(members.last_login_ip)僅一人可參加
 * @property int|null $phone_block 1=同手機號僅一人可參加
 * @property int|null $account_block 1=同真實姓名(first_name)僅一人可參加
 * @property int $restrict_new_member 1=僅活動開始後註冊的玩家可參加，0=活動開始前註冊的玩家也可參加
 * @property int $sort 後台活動列表排序，數字越大越前面
 * @property int $status 狀態; 0:停用, 1:啟用
 * @property Carbon|null $start_time
 * @property Carbon|null $end_time
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Agent> $configExcludeAgents 排除代理（含下線）
 *
 * @method static Builder<static>|NewbieChallengeConfig newModelQuery()
 * @method static Builder<static>|NewbieChallengeConfig newQuery()
 * @method static Builder<static>|NewbieChallengeConfig query()
 *
 * @mixin \Eloquent
 */
class NewbieChallengeConfig extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'allowed_vendor_ids' => 'array',
        'allowed_game_type_ids' => 'array',
        'excluded_vendor_ids' => 'array',
        'bonus_flow_vendor_ids' => 'array',
        'bonus_flow_game_type_ids' => 'array',
        'excluded_bonus_flow_vendor_ids' => 'array',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function configLangs(): HasMany
    {
        return $this->hasMany(NewbieChallengeConfigLang::class, 'config_id');
    }

    public function configExcludeAgents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'newbie_challenge_config_exclude_agents', 'config_id', 'agent_id');
    }
}
