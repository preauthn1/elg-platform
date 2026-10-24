<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $member_id 會員系統編號
 * @property int $game_id 遊戲系統編號
 * @property int $status 狀態0:取消,1:使用中
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|FavouriteGame newModelQuery()
 * @method static Builder<static>|FavouriteGame newQuery()
 * @method static Builder<static>|FavouriteGame query()
 *
 * @mixin \Eloquent
 */
class FavouriteGame extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
