<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $batch_id 批次ID
 * @property string $code 優惠碼
 * @property string|null $used_at 使用時間
 * @property int $member_id 會員系統編號
 * @property string|null $mobile 手機號碼
 * @property int|null $coupon_batch_prize_id 獎項ID; 對應 coupon_batch_prizes.id
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read CouponBatch|null $batch
 *
 * @method static \Database\Factories\CouponCodeFactory factory($count = null, $state = [])
 * @method static Builder<static>|CouponCode newModelQuery()
 * @method static Builder<static>|CouponCode newQuery()
 * @method static Builder<static>|CouponCode query()
 *
 * @mixin \Eloquent
 */
class CouponCode extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CouponBatch::class, 'batch_id', 'id');
    }
}
