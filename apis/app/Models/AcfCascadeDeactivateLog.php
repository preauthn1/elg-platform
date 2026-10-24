<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * AgentCashFlow 下架連動 ACFOT 的審計（event-log 模式，每次處理 = 新 row）。
 *
 * 設計要點：ACF 重新啟用時「不」自動把 ACFOT 開回，避免打開營運刻意個別停用的渠道；
 * 還原改為顯式操作，依本表 action=cascade_deactivate 且 restored_by_log_id=NULL 的列
 * 把 acfot.status 寫回 from_status，還原後回填 restored_by_log_id 形成閉環（不重複還原）。
 *
 * @property int $id
 * @property string|null $connection 執行當下的 DB connection（白標）
 * @property int $agent_cash_flow_id 觸發連動的 agent_cash_flows.id
 * @property int $agent_cash_flow_order_type_id 被連動處理的 agent_cash_flow_order_types.id
 * @property int $order_type_id 對應 order_types.id（冗余便於追查）
 * @property string $action cascade_deactivate / restore / soft_delete / restore_deleted
 * @property int $from_status 處理前的 acfot.status（還原時寫回此值）
 * @property string $source 觸發來源：observer / command
 * @property string|null $detail 補充說明
 * @property int|null $restored_by_log_id 已被哪筆 restore log 還原；NULL=尚未還原
 * @property Carbon $created_at 建立日期
 *
 * @method static Builder<static>|AcfCascadeDeactivateLog newModelQuery()
 * @method static Builder<static>|AcfCascadeDeactivateLog newQuery()
 * @method static Builder<static>|AcfCascadeDeactivateLog query()
 *
 * @mixin \Eloquent
 */
class AcfCascadeDeactivateLog extends Model
{
    /** 動作：連動下架（acfot status → 0） */
    public const string ACTION_CASCADE_DEACTIVATE = 'cascade_deactivate';

    /** 動作：還原（acfot status 寫回 from_status） */
    public const string ACTION_RESTORE = 'restore';

    /** 動作：連動軟刪（ACF 軟刪時旗下 acfot deleted_at 一併設值；acfot_id=0 的列＝ACF 自身） */
    public const string ACTION_SOFT_DELETE = 'soft_delete';

    /** 動作：軟刪還原（deleted_at 清空、status 原樣保留） */
    public const string ACTION_RESTORE_DELETED = 'restore_deleted';

    /** 來源：Model Observer（Eloquent 路徑） */
    public const string SOURCE_OBSERVER = 'observer';

    /** 來源：Artisan 命令 */
    public const string SOURCE_COMMAND = 'command';

    protected $table = 'acf_cascade_deactivate_logs';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * 尚待還原的連動下架紀錄（action=cascade_deactivate 且未被還原）
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePendingRestore(Builder $query): Builder
    {
        return $query
            ->where('action', self::ACTION_CASCADE_DEACTIVATE)
            ->whereNull('restored_by_log_id');
    }
}
