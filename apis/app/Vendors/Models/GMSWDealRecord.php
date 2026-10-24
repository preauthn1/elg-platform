<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員系統編號
 * @property string $username GM uname
 * @property int|null $bet_record_id FK gmsw_bet_records.id；type=5/6 無對應 BR 時可 nullable
 * @property string $bet_id GM session_id（round ID）
 * @property string $reference_no GM order_id — 每筆 callback 冪等鍵
 * @property int $action GM type: 1=Bet, 2=Cancel, 3=Win, 4=EndRound, 5=LuckwinBonus, 6=FutureFee
 * @property numeric $amount 本次金額（正數，方向依 action 判斷）
 * @property numeric|null $balance_before 扣/加前餘額
 * @property numeric|null $balance_after 扣/加後餘額
 * @property array<array-key, mixed>|null $info 原始 callback payload
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read GMSWBetRecord|null $betRecord
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GMSWDealRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GMSWDealRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GMSWDealRecord query()
 *
 * @mixin \Eloquent
 */
class GMSWDealRecord extends Model
{
    protected $table = 'gmsw_deal_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'info' => 'array',
    ];

    public function betRecord(): BelongsTo
    {
        return $this->belongsTo(GMSWBetRecord::class, 'bet_record_id');
    }
}
