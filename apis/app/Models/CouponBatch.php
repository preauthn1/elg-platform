<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $batch_code 批次序號
 * @property int $agent_id 代理編號
 * @property int $all_downline 是否開啟整條線路
 * @property int $type 1:點數, 2:禮碼
 * @property int $reward_mode 獎勵模式; 1:固定金額, 2:隨機獎勵
 * @property int $quantity 數量
 * @property numeric $amount 金額
 * @property int $flow_limit_multiple 稽核流水倍數
 * @property int $receive_limit 會員可領取次數 0:無限制
 * @property int $mobile_daily_limit 手機號每日可領取次數 0:無限制
 * @property int $realname_required 是否需要實名認證
 * @property int $status 狀態; 0:關閉, 1:開啟
 * @property string $start_time 開始時間
 * @property string $end_time 結束時間
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 操作者
 * @property-read Agent|null $agent
 * @property-read Collection<int, CouponCode> $codes
 * @property-read int|null $codes_count
 * @property-read Collection<int, CouponBatchPrize> $prizes
 * @property-read int|null $prizes_count
 *
 * @method static Builder<static>|CouponBatch newModelQuery()
 * @method static Builder<static>|CouponBatch newQuery()
 * @method static Builder<static>|CouponBatch query()
 *
 * @mixin \Eloquent
 */
class CouponBatch extends Model
{
    protected $table = 'coupon_batches';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function codes()
    {
        return $this->hasMany(CouponCode::class, 'batch_id', 'id');
    }

    public function prizes()
    {
        return $this->hasMany(CouponBatchPrize::class, 'batch_id', 'id');
    }

    public function agent()
    {
        return $this->belongsTo(Agent::class, 'agent_id', 'id');
    }
}
