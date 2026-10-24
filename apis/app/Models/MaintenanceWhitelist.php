<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $type 類型: 1.IP 2.代理
 * @property string $value 白名單的代理或IP
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceWhitelist newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceWhitelist newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceWhitelist query()
 *
 * @mixin \Eloquent
 */
class MaintenanceWhitelist extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
