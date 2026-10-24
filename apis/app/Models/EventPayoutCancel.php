<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $event_id events.id
 * @property int $prize_member_id prize_members.id
 * @property int $prize_content_id prize_contents.id
 * @property int $order_id orders.id
 * @property int $from_member_id members.id(邀請活動用，被邀請人會員ID)
 * @property int $member_id members.id
 * @property int $type 重複類型; 1: 真實姓名, 2: 註冊IP, 3: 手機號碼
 * @property int $block_member_id 重複的會員id
 * @property string|null $remark 備註
 * @property int $status 狀態;0:未派發、 1: 已補派
 * @property int $operator 操作人
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventPayoutCancel newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventPayoutCancel newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventPayoutCancel query()
 *
 * @mixin \Eloquent
 */
class EventPayoutCancel extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
