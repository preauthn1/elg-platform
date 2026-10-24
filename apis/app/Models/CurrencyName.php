<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $language_id 語言系統編號
 * @property int $currency_id 貨幣系統編號
 * @property string $name 貨幣名稱
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|CurrencyName newModelQuery()
 * @method static Builder<static>|CurrencyName newQuery()
 * @method static Builder<static>|CurrencyName query()
 *
 * @mixin \Eloquent
 */
class CurrencyName extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $attributes = [
        'admin' => 0,
    ];
}
