<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 簡訊商配置
 *
 * @property int $id 流水號
 * @property int $sms_gateway_group_id 簡訊群組ID(sms_gateway_groups.id)
 * @property int $sms_gateway_id 簡訊商ID
 * @property \Illuminate\Database\Eloquent\Casts\ArrayObject<array-key, mixed> $info 簡訊商商戶資訊, 內容使用json儲存
 * @property int $sort 簡訊商排序
 * @property numeric $cost 成本
 * @property int $status 狀態; 0:停用, 1:啟用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read mixed $code
 * @property-read Collection<int, Country> $countries
 * @property-read int|null $countries_count
 * @property-read mixed $insert_characters
 * @property-read mixed $left_credit_alert
 * @property-read mixed $prefix
 * @property-read SmsGateway|null $smsGateway
 * @property-read Collection<int, SmsGatewayInfoCountry> $smsGatewayInfoCountries
 * @property-read int|null $sms_gateway_info_countries_count
 * @property-read mixed $url
 *
 * @method static Builder<static>|SmsGatewayInfo newModelQuery()
 * @method static Builder<static>|SmsGatewayInfo newQuery()
 * @method static Builder<static>|SmsGatewayInfo query()
 *
 * @mixin \Eloquent
 */
class SmsGatewayInfo extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'info' => AsArrayObject::class,
    ];

    public function smsGateway(): BelongsTo
    {
        return $this->belongsTo(SmsGateway::class, 'sms_gateway_id');
    }

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'sms_gateway_info_countries', 'sms_gateway_info_id', 'country_id')
            ->wherePivot('status', 1);
    }

    public function smsGatewayInfoCountries(): HasMany
    {
        return $this->hasMany(SmsGatewayInfoCountry::class);
    }

    /**
     * 修改器：取得上層 smsGateway 的 code
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->smsGateway->code ?? ''
        );
    }

    /**
     * 修改器：取得上層 smsGateway 的 url
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->smsGateway->url ?? ''
        );
    }

    /**
     * insert_characters
     */
    protected function insertCharacters(): Attribute
    {
        $info = $this->info;

        return Attribute::make(
            get: fn (?string $value) => $info->insert_characters ?? '',
        );
    }

    /**
     * left_credit_alert
     */
    protected function leftCreditAlert(): Attribute
    {
        $info = $this->info;

        return Attribute::make(
            get: fn (?string $value) => $info->left_credit_alert ?? 0,
        );
    }

    /**
     * prefix
     */
    protected function prefix(): Attribute
    {
        $info = $this->info;

        return Attribute::make(
            get: fn (?string $value) => $info->prefix ?? '',
        );
    }
}
