<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $member_id 會員帳號系統編號(Member)
 * @property string $ip IP地址
 * @property int $status 狀態：0失敗，1成功
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|MemberLoginLog newModelQuery()
 * @method static Builder<static>|MemberLoginLog newQuery()
 * @method static Builder<static>|MemberLoginLog query()
 *
 * @mixin \Eloquent
 */
class MemberLoginLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
