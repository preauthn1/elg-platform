<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $batch_id 批次ID
 * @property int $point_type 獎勵類型; 1:平台點數, 2:禮碼
 * @property numeric $amount 獎勵金額
 * @property numeric $weight 中獎權重
 * @property int $quantity 獎品數量; 0:無限制
 * @property int $awarded 已派發數量
 * @property int $flow_limit_multiple 稽核流水倍數
 * @property int $sort 排序
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read CouponBatch|null $batch
 * @property-read Collection<int, CouponBatchPrizeImage> $images
 * @property-read int|null $images_count
 *
 * @method static Builder<static>|CouponBatchPrize newModelQuery()
 * @method static Builder<static>|CouponBatchPrize newQuery()
 * @method static Builder<static>|CouponBatchPrize query()
 *
 * @mixin \Eloquent
 */
class CouponBatchPrize extends Model
{
    protected $table = 'coupon_batch_prizes';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CouponBatch::class, 'batch_id', 'id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(CouponBatchPrizeImage::class, 'coupon_batch_prize_id', 'id');
    }
}
