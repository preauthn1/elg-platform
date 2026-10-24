<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $language_id 語言系統編號
 * @property int $type_id 訂單類型系統編號
 * @property int $cash_flow_id 金流商編號（denormalize order_types.cash_flow_id）
 * @property string $name 訂單類型名稱
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|OrderTypeName newModelQuery()
 * @method static Builder<static>|OrderTypeName newQuery()
 * @method static Builder<static>|OrderTypeName query()
 *
 * @mixin \Eloquent
 */
class OrderTypeName extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
