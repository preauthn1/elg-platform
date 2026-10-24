<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 遊戲維護主表 工單4120
 *
 * 與 VendorMaintenance（整商維護）平行：粒度到單一遊戲，一張單可跨遊戲商，
 * 實際涵蓋的遊戲在 games 關聯（game_maintenance_games）。
 *
 * @property int $id 流水號
 * @property int $type 關閉類型 1:關閉遊戲（本表獨立定義，與 VendorMaintainTypeEnum 不同義）
 * @property string $start_time 開始時間
 * @property string $end_time 結束時間
 * @property int $agent_id 維護代理ID
 * @property int $exclude_agent_id 排除代理ID
 * @property int $status 狀態 0:停用 1:啟用
 * @property int $unlock_type 解鎖方式 1:自動 2:手動
 * @property string|null $memo 備註
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|GameMaintenance newModelQuery()
 * @method static Builder<static>|GameMaintenance newQuery()
 * @method static Builder<static>|GameMaintenance query()
 *
 * @mixin \Eloquent
 */
class GameMaintenance extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /** 本單涵蓋的遊戲清單 */
    public function games(): HasMany
    {
        return $this->hasMany(GameMaintenanceGame::class, 'game_maintenance_id');
    }
}
