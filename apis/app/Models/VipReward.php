<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $name 獎勵名稱
 * @property int $status 狀態 0:未領取 1:已領取
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|VipReward newModelQuery()
 * @method static Builder<static>|VipReward newQuery()
 * @method static Builder<static>|VipReward query()
 *
 * @mixin \Eloquent
 */
class VipReward extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
