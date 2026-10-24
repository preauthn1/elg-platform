<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $event_v2_user_join_id 活動參加ID
 * @property int $member_id 會員ID
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2Invite newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2Invite newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2Invite query()
 *
 * @mixin \Eloquent
 */
class EventV2Invite extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
