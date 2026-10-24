<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員系統編號
 * @property string $username Joker playerID
 * @property int|null $bet_record_id FK jkbsw_bet_records.id；rollback 找不到時可 nullable
 * @property string $bet_id Joker roundID
 * @property string $reference_no Joker transactionNo — 每筆 callback 冪等鍵
 * @property int $action Joker action: 2=Bet,3=Win,4=Refund,5=Rollback
 * @property numeric $amount 本次金額 (正數)
 * @property numeric|null $balance_before 扣/加前餘額
 * @property numeric|null $balance_after 扣/加後餘額
 * @property array<array-key, mixed>|null $info 原始 callback payload
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read JkbswBetRecord|null $betRecord
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JkbswDealRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JkbswDealRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JkbswDealRecord query()
 *
 * @mixin \Eloquent
 */
class JkbswDealRecord extends Model
{
    protected $table = 'jkbsw_deal_records';

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
        return $this->belongsTo(JkbswBetRecord::class, 'bet_record_id');
    }
}
