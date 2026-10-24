<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $agent_group_id 代理組別vendor_customize_group_id
 * @property int $item_type 入口類型: 0 : 遊戲商, 1 : 遊戲, 2 : 類型, 3 : 站內連結
 * @property int $vendor_id 遊戲商ID
 * @property int $game_id 遊戲ID
 * @property int $type_id 遊戲類型ID
 * @property string $path 連結
 * @property string|null $name 遊戲商名稱
 * @property string|null $image 遊戲商圖檔路徑
 * @property string|null $front_page_image 遊戲商前置頁圖片
 * @property string|null $app_icon 遊戲商圖檔路徑
 * @property int|null $is_new 新遊戲標記
 * @property int|null $is_hot 熱門遊戲標記
 * @property int|null $is_event 活動遊戲標記
 * @property int|null $only_one_game 獨立入口
 * @property int|null $status 前台顯示狀態 0: 關閉, 1: 正常, 2: coming soon
 * @property int $sorts 排序權重
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作人員
 * @property-read Collection<int, Agent> $agents
 * @property-read int|null $agents_count
 * @property-read Vendor|null $vendor
 * @property-read Collection<int, VendorMaintenance> $vendorMaintenances
 * @property-read int|null $vendor_maintenances_count
 *
 * @method static Builder<static>|VendorCustomizedItem newModelQuery()
 * @method static Builder<static>|VendorCustomizedItem newQuery()
 * @method static Builder<static>|VendorCustomizedItem query()
 *
 * @mixin \Eloquent
 */
class VendorCustomizedItem extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class, 'vendor_customize_group_id', 'agent_group_id');
    }

    public function vendor(): HasOne
    {
        return $this->hasOne(Vendor::class, 'id', 'vendor_id');
    }

    public function vendorMaintenances(): HasMany
    {
        return $this->hasMany(VendorMaintenance::class, 'vendor_id', 'vendor_id');
    }
}
