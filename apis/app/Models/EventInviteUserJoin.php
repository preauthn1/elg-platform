<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id 會員系統編號
 * @property int $invite_member_id 邀請人會員系統編號
 * @property int|null $register_ip 註冊當下的IP
 * @property string|null $register_mobile 註冊當下的手機號
 * @property string|null $register_realname 註冊當下的真實姓名
 * @property int $block_ip_member_id IP重複的會員系統編號，>0 則不派發
 * @property int $block_mobile_member_id 手機號重複的會員系統編號，>0 則不派發
 * @property int $block_realname_member_id 真實姓名重複的會員系統編號，>0 則不派發
 * @property int $realname_status 真實姓名認證獎勵是否領取 0:未領取, 1:已領取
 * @property int $deposit_status 儲值獎勵是否領取 0:未領取, 1:已領取
 * @property numeric $bet 累積流水
 * @property numeric $point 可領取金額
 * @property numeric $receive 已領取金額
 * @property int $status 狀態 0:進行中, 1:結束, 2:有重複玩家不計算
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin
 * @property-read Member|null $member
 *
 * @method static Builder<static>|EventInviteUserJoin newModelQuery()
 * @method static Builder<static>|EventInviteUserJoin newQuery()
 * @method static Builder<static>|EventInviteUserJoin query()
 *
 * @mixin \Eloquent
 */
class EventInviteUserJoin extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
