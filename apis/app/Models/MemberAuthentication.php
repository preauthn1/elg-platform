<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property int $member_id 主帳號的系統編號(不等於0就是子帳號)
 * @property int $type 類型; 1:Google2FA
 * @property string|null $secret secret key
 * @property string|null $image img
 * @property int $status 帳號狀態 0:停用,1:啟用
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|MemberAuthentication newModelQuery()
 * @method static Builder<static>|MemberAuthentication newQuery()
 * @method static Builder<static>|MemberAuthentication query()
 *
 * @mixin \Eloquent
 */
class MemberAuthentication extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $hidden = [
        'secret',
    ];
}
