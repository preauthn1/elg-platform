<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $sub_wallet_id 子錢包ID
 * @property string $address 錢包地址
 * @property string $balance 錢包餘額 (參考用)
 * @property int $status 流程狀態 1:閒置, 2:等待付款, 3:冷卻中
 * @property int $deployment_status 部屬狀態 0:尚未部屬, 1:部屬中, 2:已部
 * @property int $is_enabled 是否啟用 0:停用, 1:啟用
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Order> $orders
 * @property-read int|null $orders_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Wallet query()
 *
 * @mixin \Eloquent
 */
class Wallet extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'wallet_id', 'id');
    }
}
