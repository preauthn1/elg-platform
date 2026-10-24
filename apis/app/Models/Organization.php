<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name 組織名稱
 * @property int $currency_id 使用的貨幣
 * @property string $memo 備註
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|Organization newModelQuery()
 * @method static Builder<static>|Organization newQuery()
 * @method static Builder<static>|Organization query()
 *
 * @mixin \Eloquent
 */
class Organization extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
