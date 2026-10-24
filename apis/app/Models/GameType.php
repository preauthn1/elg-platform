<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property int $parent 上層選單的系統編號
 * @property string $name 類別名稱
 * @property int $status 狀態 0:停用,1:啟用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|GameType newModelQuery()
 * @method static Builder<static>|GameType newQuery()
 * @method static Builder<static>|GameType query()
 *
 * @mixin \Eloquent
 */
class GameType extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
