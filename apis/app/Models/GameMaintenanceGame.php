<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 遊戲維護涵蓋的遊戲清單 工單4120
 *
 * 只存 game_id：遊戲商與類型由 game 關聯推導，不另存以免與 games 不一致。
 *
 * @property int $id 流水號
 * @property int $game_maintenance_id game_maintenances.id
 * @property int $game_id games.id
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|GameMaintenanceGame newModelQuery()
 * @method static Builder<static>|GameMaintenanceGame newQuery()
 * @method static Builder<static>|GameMaintenanceGame query()
 *
 * @mixin \Eloquent
 */
class GameMaintenanceGame extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(GameMaintenance::class, 'game_maintenance_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
