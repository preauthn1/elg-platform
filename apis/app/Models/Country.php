<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property string $code 國家代碼
 * @property string $name 國家名稱
 * @property int $country_code 國碼
 * @property string $phone_rule 電話號碼規則
 * @property int $status 狀態 0:停用,1:啟用
 * @property int $sort
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Collection<int, CountryName> $countryNames
 * @property-read int|null $country_names_count
 * @property-read Collection<int, SmsGatewayInfo> $smsGatewayInfos
 * @property-read int|null $sms_gateway_infos_count
 *
 * @method static Builder<static>|Country newModelQuery()
 * @method static Builder<static>|Country newQuery()
 * @method static Builder<static>|Country query()
 *
 * @mixin \Eloquent
 */
class Country extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function countryNames(): HasMany
    {
        return $this->hasMany(CountryName::class, 'country_id');
    }

    public function smsGatewayInfos()
    {
        return $this->belongsToMany(SmsGatewayInfo::class, 'sms_gateway_info_countries', 'country_id', 'sms_gateway_info_id')
            ->wherePivot('status', 1);
    }
}
