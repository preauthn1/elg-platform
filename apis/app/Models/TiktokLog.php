<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id 會員ID
 * @property string $session_id session_id
 * @property string $clientID clientID
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|TiktokLog newModelQuery()
 * @method static Builder<static>|TiktokLog newQuery()
 * @method static Builder<static>|TiktokLog query()
 *
 * @mixin \Eloquent
 */
class TiktokLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
