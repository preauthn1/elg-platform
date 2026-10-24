<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Score\ScoreBatchStatusEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Models\Traits\HasMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 積分批次——積分餘額的唯一真相（target/tickets#4233）。
 *
 * 一次發放一列，各自帶有效期；會員可用積分＝所有 ACTIVE 且未到期批次的 remaining_score 加總，
 * member_wallets 的 CODE_SCORE 列是它的投影。**加減一律經 MemberScoreService**，
 * 禁止在別處直接改 remaining_score／status——那會讓錢包投影與本表對不上且不報錯。
 *
 * @property int $id
 * @property int $member_id 會員系統編號
 * @property numeric $score 本批發放積分；業務規則限 2 位小數
 * @property numeric $remaining_score 本批剩餘積分；業務規則限 2 位小數
 * @property Carbon|null $expired_at 到期時間（UTC；發放時刻+N小時後進位到下一個 10 分鐘刻度）；null=無限期，FIFO 排最後
 * @property ScoreSourceTypeEnum $source_type 來源：活動獎勵發放／人工調整（日後新來源只加 case）
 * @property int $source_id 來源單據 id，指向的表依 source_type（活動獎勵＝event_v2_user_joins.id）；人工調整為 0
 * @property ScoreBatchStatusEnum $status 1:有效 2:已用罄 3:已到期失效
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin 操作者ID
 * @property-read Member|null $member
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MemberScoreLog> $logs
 *
 * @method static Builder<static>|MemberScoreBatch newModelQuery()
 * @method static Builder<static>|MemberScoreBatch newQuery()
 * @method static Builder<static>|MemberScoreBatch query()
 *
 * @mixin \Eloquent
 */
class MemberScoreBatch extends Model
{
    use HasMember;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'expired_at' => 'datetime',
        'source_type' => ScoreSourceTypeEnum::class,
        'status' => ScoreBatchStatusEnum::class,
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(MemberScoreLog::class, 'batch_id');
    }

    /**
     * FIFO 扣點順序：先扣到期日最早的；無到期日（null）排最後；同到期日先扣早建立的。
     *
     * `(expired_at IS NULL)` 回 0/1，讓 null 自動排到最後——對上工單「無到期日的批次完全沒被動到」。
     * 寫成 scope 是為了讓 deduct 與到期排程用同一個順序，不在呼叫端各寫一份。
     */
    public function scopeFifo(Builder $query): Builder
    {
        return $query->orderByRaw('(expired_at IS NULL) ASC, expired_at ASC, created_at ASC, id ASC');
    }

    /**
     * 可用批次：有效、仍有剩餘、且在 $at 時點尚未到期（無到期日恆可用）。
     */
    public function scopeAvailableAt(Builder $query, Carbon $at): Builder
    {
        return $query
            ->where('status', ScoreBatchStatusEnum::ACTIVE->value)
            ->where('remaining_score', '>', 0)
            ->where(function (Builder $q) use ($at): void {
                $q->whereNull('expired_at')
                    ->orWhere('expired_at', '>', $at);
            });
    }

    /**
     * 已到期但仍標有效的批次：到期排程與扣點前即時到期共用的篩選。
     */
    public function scopeDueAt(Builder $query, Carbon $at): Builder
    {
        return $query
            ->where('status', ScoreBatchStatusEnum::ACTIVE->value)
            ->whereNotNull('expired_at')
            ->where('expired_at', '<=', $at);
    }
}
