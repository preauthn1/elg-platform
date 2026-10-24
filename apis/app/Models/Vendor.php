<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\VendorObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property string $name 遊戲商名稱
 * @property string $code 遊戲商表格前綴
 * @property string|null $tname agent後台會用tname來找對應的xxx_accounts
 * @property string|null $third_party_code 三方代碼; 如果是聚合遊戲商, 就用同一個代碼
 * @property numeric $rate 平台點數與遊戲點數的比例
 * @property numeric $percentage 拆帳比例
 * @property string $image 遊戲商圖片
 * @property string|null $app_icon 遊戲商app的圖示
 * @property string $info API使用資訊
 * @property int $is_free 是否為免盤費商戶
 * @property int $is_new 是否顯示新遊戲商標籤0:不顯示,1:顯示
 * @property int $is_hot 是否顯示熱門遊戲商標籤0:不顯示,1:顯示
 * @property int $is_event 是否顯示活動遊戲商標籤0:不顯示,1:顯示
 * @property int $need_login 是否需另外登入0:不需要1:需要
 * @property int|null $only_one_game 只有一款遊戲
 * @property int|null $is_single_wallet 是否為單一錢包遊戲
 * @property int $is_slow 遊戲商API回應是否較慢，0:否、1:是
 * @property int $event_lock 是否可以參加鎖分活動0:不可，1:可以
 * @property int $show_on_mobile Mobile版是否顯示0:不顯示，1:顯示
 * @property int $show_on_pc PC版是否顯示0:不顯示，1:顯示
 * @property int $show_on_app APP版是否顯示 0:不顯示，1顯示
 * @property int $show_on_profit_share 佔成會員是否顯示
 * @property int $calc_on_profit_share 佔成會員是否計算至佔成(目前4D跟M9BET排除)
 * @property int $open_window 是否另開視窗; 0:不另開, 1:另開
 * @property int $summary_daily summary時間; 0:不計算, 1:每小時, 2:每天
 * @property string|null $whitelist 白名單; ip1,ip2,ip3,ip4,...
 * @property string|null $callback_path 無縫錢包回調路徑後綴；NULL 代表用 Driver 預設
 * @property numeric $valid_bet_rate 有效投注轉換比例
 * @property int|null $calc_flow_limit 是否計算稽核流水
 * @property string|null $remark 備註
 * @property string|null $memo
 * @property int $calc_time_zone 後台跟三方後台的計算時區
 * @property int $unbind 是否可以解綁; 0:否, 1:是
 * @property int|null $sort 排序
 * @property int $status 狀態 0:關閉,1:正常
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Collection<int, AgentVendor> $agentVendors
 * @property-read int|null $agent_vendors_count
 * @property-read Collection<int, Game> $games
 * @property-read int|null $games_count
 * @property-read Collection<int, VendorCustomizedItem> $vendorCustomizedItems
 * @property-read int|null $vendor_customized_items_count
 * @property-read VendorImage|null $vendorImage
 * @property-read Collection<int, VendorInfo> $vendorInfos
 * @property-read int|null $vendor_infos_count
 * @property-read VendorMaintenance|null $vendorLastMaintenance
 * @property-read Collection<int, VendorMaintenance> $vendorMaintenances
 * @property-read int|null $vendor_maintenances_count
 * @property-read Collection<int, VendorRequestFail> $vendorRequestFails
 * @property-read int|null $vendor_request_fails_count
 *
 * @method static \Database\Factories\VendorFactory factory($count = null, $state = [])
 * @method static Builder<static>|Vendor newModelQuery()
 * @method static Builder<static>|Vendor newQuery()
 * @method static Builder<static>|Vendor query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy(VendorObserver::class)]
class Vendor extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agentVendors(): HasMany
    {
        return $this->hasMany(AgentVendor::class, 'vendor_id');
    }

    public function vendorInfos(): HasMany
    {
        return $this->hasMany(VendorInfo::class, 'vendor_id');
    }

    // public function vendorImages(): HasMany
    // {
    //     return $this->hasMany(VendorImage::class, 'vendor_id')->where('status', 1);
    // }

    public function vendorImage(): HasOne
    {
        return $this->hasOne(VendorImage::class, 'vendor_id');
    }

    /**
     * TODO: 應該只會用到 vendorLastMaintenance
     */
    public function vendorMaintenances(): HasMany
    {
        return $this->hasMany(VendorMaintenance::class, 'vendor_id');
    }

    /**
     * 僅返回當前有效的維護紀錄 (最新一筆)
     */
    public function vendorLastMaintenance(): HasOne
    {
        return $this->hasOne(VendorMaintenance::class, 'vendor_id')
            ->where('status', 1)
            ->where('start_time', '<=', now())
            ->where('end_time', '>=', now())
            ->orderByDesc('end_time');
    }

    public function vendorRequestFails(): HasMany
    {
        return $this->hasMany(VendorRequestFail::class, 'vendor_id');
    }

    public function vendorCustomizedItems(): HasMany
    {
        return $this->hasMany(VendorCustomizedItem::class, 'vendor_id');
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class, 'vendor_id');
    }

    // protected function isMaintain(): Attribute
    // {
    //     return Attribute::get(function () {
    //         $agent = Context::getHidden(ContextKeyEnum::AGENT->value);
    //
    //         return $this->vendorLastMaintenance()
    //             ->where('type', VendorMaintainTypeEnum::BOTH_CLOSED->value)
    //             ->whereIn('agent_id', $agent->hierarchy_array)
    //             ->whereNotIn('exclude_agent_id', $agent->hierarchy_array)
    //             ->exists();
    //     });
    // }
}
