<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name 商家名稱
 * @property string $code 商家代碼
 * @property string $secret_key Secret Key
 * @property int $status 狀態 0:停用,1:啟用
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MerchantBalance> $balances
 * @property-read int|null $balances_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Merchant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Merchant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Merchant query()
 *
 * @mixin \Eloquent
 */
class Merchant extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function balances(): HasMany
    {
        return $this->hasMany(MerchantBalance::class, 'merchant_id', 'id');
    }
}
