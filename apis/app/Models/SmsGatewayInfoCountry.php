<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sms_gateway_info_id 簡訊商戶資訊ID; sms_gateway_infos.id
 * @property int $country_id 國家ID; countries.id
 * @property int $status 狀態; 1:啟用, 0:停用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read SmsGatewayInfo|null $smsGatewayInfo
 *
 * @method static Builder<static>|SmsGatewayInfoCountry newModelQuery()
 * @method static Builder<static>|SmsGatewayInfoCountry newQuery()
 * @method static Builder<static>|SmsGatewayInfoCountry query()
 *
 * @mixin \Eloquent
 */
class SmsGatewayInfoCountry extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function smsGatewayInfo(): BelongsTo
    {
        return $this->belongsTo(SmsGatewayInfo::class, 'sms_gateway_info_id');
    }
}
