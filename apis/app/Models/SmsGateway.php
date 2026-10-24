<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property string $name 簡訊商名稱
 * @property string $code 簡訊商代碼
 * @property string $info 簡訊商輸入資訊
 * @property string $url 簡訊商 API URL
 * @property string $memo 備註
 * @property int $status 狀態; 0:停用, 1:啟用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read Collection<int, SmsGatewayInfo> $smsGatewayInfos
 * @property-read int|null $sms_gateway_infos_count
 *
 * @method static Builder<static>|SmsGateway newModelQuery()
 * @method static Builder<static>|SmsGateway newQuery()
 * @method static Builder<static>|SmsGateway query()
 *
 * @mixin \Eloquent
 */
class SmsGateway extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function smsGatewayInfos(): HasMany
    {
        return $this->hasMany(SmsGatewayInfo::class, 'sms_gateway_id');
    }
}
