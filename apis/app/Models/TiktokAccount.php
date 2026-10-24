<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $media_id 媒體ID
 * @property string $username 使用者名稱
 * @property string $password 密碼
 * @property int $member_id 會員ID
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|TiktokAccount newModelQuery()
 * @method static Builder<static>|TiktokAccount newQuery()
 * @method static Builder<static>|TiktokAccount query()
 *
 * @mixin \Eloquent
 */
class TiktokAccount extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
