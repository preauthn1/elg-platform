<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id members.id
 * @property int $transaction_log_id transaction_logs.id(轉入)
 * @property numeric $amount 金額
 * @property numeric $tax 稅收
 * @property int $operator members.id(操作人)
 * @property int $operator_transaction_log_id transaction_logs.id(轉出)
 * @property string|null $remark 備註
 * @property int $status 狀態; 0: 失敗, 1: 成功
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read Member|null $member
 * @property-read Member|null $operatorMember
 *
 * @method static Builder<static>|MemberTransferLog newModelQuery()
 * @method static Builder<static>|MemberTransferLog newQuery()
 * @method static Builder<static>|MemberTransferLog query()
 *
 * @mixin \Eloquent
 */
class MemberTransferLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function operatorMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'operator');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
