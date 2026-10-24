<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $agent_id 總代理id
 * @property int $flow_limit_multiple 流水限制倍數
 * @property int $ip_block 是否阻擋重複IP
 * @property int $phone_block 是否阻擋重複手機號
 * @property int $account_block 是否阻擋重複真實姓名
 * @property string|null $start_date 開始時間
 * @property string|null $end_date 結束時間
 * @property int $status 狀態
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CheckinPrize> $prizes
 * @property-read int|null $prizes_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckinActivity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckinActivity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckinActivity query()
 *
 * @mixin \Eloquent
 */
class CheckinActivity extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    // protected $casts = [
    //     'start_date' => 'datetime',
    //     'end_date' => 'datetime',
    // ];

    /**
     * 關聯的獎勵
     */
    public function prizes(): HasMany
    {
        return $this->hasMany(CheckinPrize::class);
    }
}
