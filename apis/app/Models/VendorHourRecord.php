<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property string $date_hour 時間
 * @property int $member_id 會員系統編號
 * @property int $vendor_id 營運商編號
 * @property int $game_id 遊戲編號
 * @property numeric $bet 投注額
 * @property int $bet_received 己兌換的投注額
 * @property numeric $win 勝分
 * @property int $win_received 己兌換的贏分
 * @property numeric $win_lose 輸贏
 * @property int $win_lose_received 己兌換的輸贏
 * @property string|null $last_bet_time 最後投注時間
 * @property numeric|null $valid_bet_rate
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read Member|null $member
 * @property-read Vendor|null $vendor
 * @property-read Collection<int, VendorCustomizedItem> $vendorCustomizedItems
 * @property-read int|null $vendor_customized_items_count
 *
 * @method static \Database\Factories\VendorHourRecordFactory factory($count = null, $state = [])
 * @method static Builder<static>|VendorHourRecord newModelQuery()
 * @method static Builder<static>|VendorHourRecord newQuery()
 * @method static Builder<static>|VendorHourRecord query()
 *
 * @mixin \Eloquent
 */
class VendorHourRecord extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function vendorCustomizedItems(): HasMany
    {
        return $this->hasMany(VendorCustomizedItem::class, 'vendor_id', 'vendor_id');
    }
}
