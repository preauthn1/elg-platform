<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vip_content_id vip_contents.id
 * @property int $game_type_id game_types.id
 * @property numeric $proportion 返水比例
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $admin 管理員編號
 * @property-read VipContentReward|null $vipContentReward
 *
 * @method static Builder<static>|VipRebateProportion newModelQuery()
 * @method static Builder<static>|VipRebateProportion newQuery()
 * @method static Builder<static>|VipRebateProportion query()
 *
 * @mixin \Eloquent
 */
class VipRebateProportion extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function vipContentReward()
    {
        return $this->belongsTo(VipContentReward::class, 'vip_content_id', 'vip_content_id');
    }
}
