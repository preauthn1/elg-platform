<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $detail_id 活動條件系統編號
 * @property int $member_id 會員系統編號
 * @property numeric $point 目前點數
 * @property int|null $received 已兌換過的金額
 * @property string|null $last_bet_time
 * @property int $status 派獎狀態0:未達標1:已達標2:已派獎
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|EventProcess newModelQuery()
 * @method static Builder<static>|EventProcess newQuery()
 * @method static Builder<static>|EventProcess query()
 *
 * @mixin \Eloquent
 */
class EventProcess extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
