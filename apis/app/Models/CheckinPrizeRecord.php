<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $checkin_record_id 打卡紀錄id
 * @property int $checkin_prize_id 打卡獎品id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read CheckinPrize|null $checkinPrize
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckinPrizeRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckinPrizeRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckinPrizeRecord query()
 *
 * @mixin \Eloquent
 */
class CheckinPrizeRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function checkinPrize()
    {
        return $this->belongsTo(CheckinPrize::class, 'checkin_prize_id', 'id');
    }
}
