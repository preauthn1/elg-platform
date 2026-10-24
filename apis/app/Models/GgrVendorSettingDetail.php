<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 推廣代理 GGR 遊戲商設置明細 工單4123
 *
 * 每列＝vendor_info × game_type 的一個階層；同組合以 threshold_amount
 * 為階梯鍵逐階嚴格遞增，結算取「公司輸贏達標的最高階」設定。
 * excluded_game_ids / status 為同組合各階共用，僅存 tier=1 列。
 * min_winlose / max_ggr 以 0 表示無最低限制／無上限（2026-08-31 由 NULL 哨兵
 * 改為 0，NOT NULL default 0，對齊工單「0=不限制」語意）。
 *
 * @property int $id 流水號(不對外引用,update全量替換)
 * @property int $setting_id ggr_vendor_settings.id
 * @property int $vendor_id vendors.id(結算彙總鍵)
 * @property int $vendor_info_id vendor_infos.id(彈窗列顯示主體)
 * @property int $game_type_id game_types.id(彈窗tab維度)
 * @property int $tier 階序(1-based)
 * @property array|null $excluded_game_ids 排除遊戲id陣列(存tier=1列)
 * @property int $status 該組合開關 0:不列入結算 1:啟用(存tier=1列)
 * @property string $threshold_amount 計算金額>=(階梯鍵,0=不限制)
 * @property int $min_winlose 最低遊戲輸贏(加權後下限,看絕對值,0=不限制)
 * @property int $max_ggr 最大GGR(加權後上限,看絕對值截斷保留正負,0=無上限)
 * @property string $weight_rate 加權%
 * @property int $admin 更新者ID
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|GgrVendorSettingDetail newModelQuery()
 * @method static Builder<static>|GgrVendorSettingDetail newQuery()
 * @method static Builder<static>|GgrVendorSettingDetail query()
 *
 * @mixin \Eloquent
 */
class GgrVendorSettingDetail extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'excluded_game_ids' => 'array',
        ];
    }

    /** 所屬遊戲商設置主表 */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(GgrVendorSetting::class, 'setting_id');
    }
}
