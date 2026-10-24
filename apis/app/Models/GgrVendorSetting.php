<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 推廣代理 GGR 遊戲商設置主表 工單4123
 *
 * 一筆＝一份「遊戲商設置」，明細（vendor_info × game_type × 階層）在
 * GgrVendorSettingDetail。4121 方案以本表 id 為引用契約：不硬刪、
 * 明細全量替換不影響此 id；停用（status=0）後 4121 不可新選，既有引用不受影響。
 * 僅總代使用，各總代以 general_agent_id 隔離、彼此不互見。
 *
 * @property int $id 流水號(4121引用契約)
 * @property int $general_agent_id 所屬總代agents.id(可見性軸)
 * @property string $name 設置名稱
 * @property int $status 狀態 0:停用 1:啟用
 * @property int $created_by 建立人員ID
 * @property int $admin 更新者ID
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|GgrVendorSetting newModelQuery()
 * @method static Builder<static>|GgrVendorSetting newQuery()
 * @method static Builder<static>|GgrVendorSetting query()
 *
 * @mixin \Eloquent
 */
class GgrVendorSetting extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /** 明細列（vendor_info × game_type × 階層，update 全量替換） */
    public function details(): HasMany
    {
        return $this->hasMany(GgrVendorSettingDetail::class, 'setting_id');
    }

    /** 建立此設置的代理 */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'created_by');
    }

    /** 最後編輯此設置的代理 */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'admin');
    }
}
