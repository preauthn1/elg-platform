<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $order_id ton_orders.id
 * @property int $type 類型 1:建立訂單紀錄, 2:ton交易紀錄, 3:callback紀錄
 * @property string|null $request_url request url
 * @property string|null $request_data request data
 * @property string|null $response_data response data
 * @property int $http_code http code
 * @property string|null $exception 拋出的 exception message
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderLog query()
 *
 * @mixin \Eloquent
 */
class OrderLog extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
