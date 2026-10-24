<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property string $summary_time 時間
 * @property int $bet_record_id
 * @property int $member_id 會員系統編號
 * @property int $vendor_id 遊戲商系統編號
 * @property int $game_id 遊戲系統編號
 * @property string $reference_no 來源單號
 * @property numeric $bet 投注金額
 * @property numeric $valid_bet 有效投注金額
 * @property numeric $win_lose 輸贏金額
 * @property string $bet_time 投注時間(UTC)
 * @property string $payout_time 派彩、結算時間(UTC)
 * @property string $record_time xxx_bet_record.created_at
 * @property string $settlement_time 算帳時間
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Game|null $game
 * @property-read Member|null $member
 * @property-read Vendor|null $vendor
 *
 * @method static Builder<static>|EventTotalBetRecord newModelQuery()
 * @method static Builder<static>|EventTotalBetRecord newQuery()
 * @method static Builder<static>|EventTotalBetRecord query()
 *
 * @mixin \Eloquent
 */
class EventTotalBetRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
