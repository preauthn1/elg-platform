<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $agent_id 代理id
 * @property int $exclude_agent_id 排除代理id
 * @property int $vendor_id 遊戲商
 * @property numeric $trigger_amount 觸發金額
 * @property int $status
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MarqueeWinPoint newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MarqueeWinPoint newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MarqueeWinPoint query()
 *
 * @mixin \Eloquent
 */
class MarqueeWinPoint extends Model
{
    //
    protected $guarded = ['id', 'created_at', 'updated_at'];
}
