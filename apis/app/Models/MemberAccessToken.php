<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property int $member_id 會員帳號系統編號(Member)
 * @property string $token Token
 * @property string $expire_date 過期日期
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Member|null $member
 *
 * @method static Builder<static>|MemberAccessToken newModelQuery()
 * @method static Builder<static>|MemberAccessToken newQuery()
 * @method static Builder<static>|MemberAccessToken query()
 *
 * @mixin \Eloquent
 */
class MemberAccessToken extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 關聯的 member 帳號
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id')
            ->where('status', 1);
    }
}
