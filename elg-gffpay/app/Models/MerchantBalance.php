<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int $currency_id
 * @property string $balance 錢包餘額
 * @property int $status 狀態 0:停用,1:啟用
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property-read Currency|null $currency
 * @property-read Merchant|null $merchant
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MerchantBalance newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MerchantBalance newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MerchantBalance query()
 *
 * @mixin \Eloquent
 */
class MerchantBalance extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id', 'id');
    }
}
