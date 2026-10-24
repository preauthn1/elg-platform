<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sms_gateway_id 簡訊商ID; sms_gateways.id
 * @property int $country_id 國家ID; countries.id
 * @property int $status 狀態; 1:啟用, 0:停用
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SmsGatewayCountry newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SmsGatewayCountry newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SmsGatewayCountry query()
 *
 * @mixin \Eloquent
 */
class SmsGatewayCountry extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
