<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Score\ScoreLogDirectionEnum;
use App\Enums\Score\ScoreLogTypeEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Models\Traits\HasMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 積分異動紀錄——只增不改（target/tickets#4233；#4232 積分紀錄頁的唯一資料源）。
 *
 * 一筆恆對應一個批次：一次扣除跨兩批就拆兩筆，各自綁被扣的那批，否則對不回到期日。
 * score 恆正、方向看 direction；before_score／after_score 是**該會員可用積分總額**的前後值，
 * 不是單一批次的餘額——由上而下累加減，最後一筆的 after_score 必須等於錢包列 balance。
 *
 * @property int $id
 * @property int $member_id 會員系統編號
 * @property int $batch_id 對應批次 member_score_batches.id
 * @property ScoreLogTypeEnum $type 做了什麼：1 系統發放 2 系統消耗 3 人工調整 4 到期失效（哪個系統看 reference_type）
 * @property ScoreLogDirectionEnum $direction 1:增加 2:扣除
 * @property numeric $score 本筆異動積分（恆正）
 * @property numeric $before_score 異動前該會員可用積分總額
 * @property numeric $after_score 異動後該會員可用積分總額
 * @property Carbon|null $expired_at 該批到期日（UTC）；扣除類留空
 * @property ScoreSourceTypeEnum $reference_type 參照類型：NONE（人工／到期）或 EVENT_V2_USER_JOIN 等
 * @property int $reference_id 參照單據 id，指向的表依 reference_type；無參照為 0
 * @property string|null $operator 操作者帳號；系統到期失效為空
 * @property string|null $remark 備註
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin 操作者ID
 * @property-read Member|null $member
 * @property-read MemberScoreBatch|null $batch
 *
 * @method static Builder<static>|MemberScoreLog newModelQuery()
 * @method static Builder<static>|MemberScoreLog newQuery()
 * @method static Builder<static>|MemberScoreLog query()
 *
 * @mixin \Eloquent
 */
class MemberScoreLog extends Model
{
    use HasMember;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'expired_at' => 'datetime',
        'type' => ScoreLogTypeEnum::class,
        'direction' => ScoreLogDirectionEnum::class,
        'reference_type' => ScoreSourceTypeEnum::class,
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MemberScoreBatch::class, 'batch_id');
    }
}
