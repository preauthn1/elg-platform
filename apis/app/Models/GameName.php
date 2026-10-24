<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $language_id 語言系統編號
 * @property int $game_id 遊戲系統編號
 * @property string $name 遊戲名稱
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|GameName newModelQuery()
 * @method static Builder<static>|GameName newQuery()
 * @method static Builder<static>|GameName query()
 *
 * @mixin \Eloquent
 */
class GameName extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
