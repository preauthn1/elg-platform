<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $vendor_id 遊戲商名稱
 * @property int $type 關閉類型，1:遊戲跟錢包都關閉、2:關閉遊戲、3：關閉錢包
 * @property string $start_time 開始時間
 * @property string $end_time 結束時間
 * @property int $agent_id 代理ID
 * @property int $exclude_agent_id 排除代理ID
 * @property int $status 狀態0:停用1:啟用
 * @property string|null $memo
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|VendorMaintenance newModelQuery()
 * @method static Builder<static>|VendorMaintenance newQuery()
 * @method static Builder<static>|VendorMaintenance query()
 *
 * @mixin \Eloquent
 */
class VendorMaintenance extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
