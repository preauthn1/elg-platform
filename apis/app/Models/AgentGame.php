<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\AgentGameObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_id 代理系統編號
 * @property int $game_id 遊戲ID
 * @property int $status 狀態; 1:啟用, 0:停用
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read Game|null $game
 *
 * @method static Builder<static>|AgentGame newModelQuery()
 * @method static Builder<static>|AgentGame newQuery()
 * @method static Builder<static>|AgentGame query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(AgentGameObserver::class)]
class AgentGame extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    // 關聯遊戲
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
