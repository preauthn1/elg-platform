<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $agent_group_id agent_group.id
 * @property \Illuminate\Database\Eloquent\Casts\ArrayObject<array-key, mixed> $setting 簡訊商資訊, json格式; {"token_expired":"token有效時間", "max_send":"有效時間最大發送次數"}
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 *
 * @method static Builder<static>|SmsGatewayGroupDetail newModelQuery()
 * @method static Builder<static>|SmsGatewayGroupDetail newQuery()
 * @method static Builder<static>|SmsGatewayGroupDetail query()
 *
 * @mixin \Eloquent
 */
class SmsGatewayGroupDetail extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'setting' => AsArrayObject::class,
    ];
}
