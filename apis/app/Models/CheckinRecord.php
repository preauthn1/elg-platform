<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $checkin_activity_id 打卡活動id
 * @property int $member_id 會員id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read CheckinActivity $checkinActivity
 * @property-read mixed $checkin_date
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CheckinPrizeRecord> $checkinPrizeRecords
 * @property-read int|null $checkin_prize_records_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckinRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckinRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckinRecord query()
 *
 * @mixin \Eloquent
 */
class CheckinRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 關聯打卡活動
     */
    public function checkinActivity(): BelongsTo
    {
        return $this->belongsTo(CheckinActivity::class, 'checkin_activity_id');
    }

    /**
     * 透過中間表 checkin_prize_records 取得簽到紀錄關聯的獎勵
     */
    public function checkinPrizeRecords(): HasMany
    {
        return $this->hasMany(CheckinPrizeRecord::class);
    }

    /**
     * 修改器: checkin_date
     */
    protected function checkinDate(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->created_at->toFrontendTime()->toDateString()
        );
    }
}
