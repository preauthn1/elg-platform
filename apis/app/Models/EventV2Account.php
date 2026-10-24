<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vendor_id 遊戲商id
 * @property int $account_id 遊戲帳號id
 * @property int $event_v2_user_join_id 參加活動id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|EventV2Account newModelQuery()
 * @method static Builder<static>|EventV2Account newQuery()
 * @method static Builder<static>|EventV2Account query()
 *
 * @mixin \Eloquent
 */
class EventV2Account extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
