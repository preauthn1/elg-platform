<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $checkin_activity_id 打卡活動id
 * @property int $day 打卡天數
 * @property int $prize_type 獎品類型 1:平台點數 2:禮碼點數 3:商城點數
 * @property numeric $prize_amount 獎品點數
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckinPrize newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckinPrize newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckinPrize query()
 *
 * @mixin \Eloquent
 */
class CheckinPrize extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
