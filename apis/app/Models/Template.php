<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name 模版名稱
 * @property string $thumbnail 模版縮圖
 * @property string|null $memo 紀錄是甚麼顏色的皮
 * @property int $status 狀態:0停用,1正常
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|Template newModelQuery()
 * @method static Builder<static>|Template newQuery()
 * @method static Builder<static>|Template query()
 *
 * @mixin \Eloquent
 */
class Template extends Model
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
