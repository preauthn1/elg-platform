<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $name brand name
 * @property string $code brand code
 * @property string $bot_id tg bot id
 * @property string $bot_token tg bot token
 * @property string $remark 備註
 * @property int $status 狀態; 0:停用, 1:啟用
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read BrandAgent|null $info
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Brand query()
 *
 * @mixin \Eloquent
 */
class Brand extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function info(): HasOne
    {
        return $this->hasOne(BrandAgent::class);
    }
}
