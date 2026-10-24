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
 * @property string $code 貨幣代碼
 * @property string $name 貨幣名稱
 * @property numeric $rate 匯率
 * @property numeric $deposit_rate 代收匯率（主要貨幣固定為 1）
 * @property numeric $withdrawal_rate 代付匯率（主要貨幣固定為 1）
 * @property int $status 狀態 0:停用,1:啟用
 * @property int $sort 排序
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Collection<int, CurrencyName> $names
 * @property-read int|null $names_count
 *
 * @method static Builder<static>|Currency newModelQuery()
 * @method static Builder<static>|Currency newQuery()
 * @method static Builder<static>|Currency query()
 *
 * @mixin \Eloquent
 */
class Currency extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function names(): HasMany
    {
        return $this->hasMany(CurrencyName::class, 'currency_id', 'id');
    }
}
