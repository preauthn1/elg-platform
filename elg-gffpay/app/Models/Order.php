<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $type 類型 1:充值, 2:提現, 3:歸集
 * @property int $status 狀態 1:等待付款, 2:成功, 3:失敗
 * @property int $merchant_id 商家ID
 * @property int $currency_id 幣別ID
 * @property string $order_no 我方訂單編號
 * @property string $merchant_order_no 商家訂單編號
 * @property int $wallet_id 錢包ID
 * @property string|null $wallet_balance 到帳時錢包餘額(參考用)
 * @property int|null $lt 交易紀錄的 logical_time, 查詢鏈上交易紀錄時使用
 * @property string|null $tx_hash 交易紀錄的 hash, 查詢鏈上交易紀錄時使用
 * @property string $amount 金額
 * @property string|null $fee 手續費
 * @property string $from_address 來源錢包地址
 * @property string $to_address 目的錢包地址
 * @property string $return_url return_url
 * @property string $callback_url callback url
 * @property int $is_callback_success 是否已經callback成功
 * @property int $callback_count callback次數
 * @property int $query_count 查詢交易紀錄次數
 * @property int $is_transfer_api_called 是否已呼叫轉帳 api
 * @property string $remark 備註
 * @property string $fail_reason 失敗原因
 * @property \Illuminate\Support\Carbon|null $transfer_api_called_at 呼叫轉帳 api 時間
 * @property \Illuminate\Support\Carbon|null $last_query_at 最後查詢時間
 * @property \Illuminate\Support\Carbon|null $last_callback_at 最後callback時間
 * @property \Illuminate\Support\Carbon|null $confirmed_at 上鏈時間
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property-read Currency|null $currency
 * @property-read \Illuminate\Database\Eloquent\Collection<int, OrderLog> $logs
 * @property-read int|null $logs_count
 * @property-read Merchant|null $merchant
 * @property-read Wallet|null $wallet
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order query()
 *
 * @mixin \Eloquent
 */
class Order extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'transfer_api_called_at' => 'datetime',
        'last_query_at' => 'datetime',
        'last_callback_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'wallet_id', 'id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(OrderLog::class, 'order_id', 'id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id', 'id');
    }
}
