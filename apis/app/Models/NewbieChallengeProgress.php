<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $config_id newbie_challenge_configs.id
 * @property int $member_id members.id，一人僅可參加一次
 * @property NewbieChallengeStageEnum $stage 狀態
 * @property numeric $activity_reward 領取的活動金快照
 * @property int|null $challenge_flow_limit_id flow_limits.id，第二階段挑戰流水鎖
 * @property numeric|null $unlocked_bonus_amount 可解鎖彩金(10~1888)
 * @property int|null $bonus_flow_limit_id flow_limits.id，第四階段彩金流水鎖
 * @property Carbon|null $bonus_window_expire_at 彩金領取資格48hr倒數到期時間
 * @property int|null $deposit_order_id orders.id，觸發彩金核銷的儲值訂單
 * @property int|null $deposit_transaction_log_id transaction_logs.id，觸發彩金核銷的後台開分（target/tickets#4352，與 deposit_order_id 互斥）
 * @property numeric|null $deposit_amount 觸發核銷的該筆儲值金額
 * @property numeric|null $claimed_bonus_amount 核銷的彩金金額，流水跑完撥款後金額不變、只是撥入主錢包
 * @property numeric $principal_total 挑戰完成後累積入金，即時查 orders 表算總額（不維護累加欄位），
 *                                    BONUS_COMPLETED 之後固定為撥款當下的歷史快照；純顯示用，不建立流水鎖
 * @property Carbon|null $joined_at
 * @property Carbon|null $challenge_completed_at
 * @property Carbon|null $bonus_completed_at
 * @property Carbon|null $principal_unlocked_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $quit_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static Builder<static>|NewbieChallengeProgress newModelQuery()
 * @method static Builder<static>|NewbieChallengeProgress newQuery()
 * @method static Builder<static>|NewbieChallengeProgress query()
 *
 * @mixin \Eloquent
 */
class NewbieChallengeProgress extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'stage' => NewbieChallengeStageEnum::class,
        'bonus_window_expire_at' => 'datetime',
        'joined_at' => 'datetime',
        'challenge_completed_at' => 'datetime',
        'bonus_completed_at' => 'datetime',
        'principal_unlocked_at' => 'datetime',
        'completed_at' => 'datetime',
        'quit_at' => 'datetime',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(NewbieChallengeConfig::class, 'config_id');
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /**
     * @return BelongsTo<FlowLimit, $this>
     */
    public function challengeFlowLimit(): BelongsTo
    {
        return $this->belongsTo(FlowLimit::class, 'challenge_flow_limit_id');
    }

    /**
     * @return BelongsTo<FlowLimit, $this>
     */
    public function bonusFlowLimit(): BelongsTo
    {
        return $this->belongsTo(FlowLimit::class, 'bonus_flow_limit_id');
    }
}
