<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $coupon_batch_prize_id 獎項ID; 對應 coupon_batch_prizes.id
 * @property int $language_id 語系系統編號
 * @property string $image_url 獎項圖片網址
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read CouponBatchPrize|null $prize
 *
 * @method static Builder<static>|CouponBatchPrizeImage newModelQuery()
 * @method static Builder<static>|CouponBatchPrizeImage newQuery()
 * @method static Builder<static>|CouponBatchPrizeImage query()
 *
 * @mixin \Eloquent
 */
class CouponBatchPrizeImage extends Model
{
    protected $table = 'coupon_batch_prize_images';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function prize(): BelongsTo
    {
        return $this->belongsTo(CouponBatchPrize::class, 'coupon_batch_prize_id', 'id');
    }
}
