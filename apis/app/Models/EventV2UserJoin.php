<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Models\Traits\HasMember;
use App\Observers\EventV2UserJoinObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $event_v2_detail_id 活動詳細ID
 * @property int $member_id 會員ID
 * @property int $order_id order ID
 * @property int|null $deposit_transaction_log_id 觸發活動的後台開分 transaction_logs.id（target/tickets#4352）
 * @property numeric $reward 獎勵金額
 * @property numeric|null $balance_limit 餘額限制
 * @property numeric|null $flow_limit 流水限制
 * @property numeric|null $withdraw_limit 提款限額
 * @property EventV2WithdrawTypeEnum $withdraw_type 領取去向，參加當下凍結自活動設定：1=主錢包 2=積分（target/tickets#4231）
 * @property numeric|null $withdraw 提款金額
 * @property numeric|null $final_balance 最終餘額
 * @property string|null $ip IP
 * @property string|null $phone 手機
 * @property int $status 1:參加2:完成3:放棄
 * @property string|null $end_time 結束時間
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read EventV2BonusCode|null $eventV2BonusCode
 * @property-read EventV2Detail|null $eventV2Detail
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EventV2Account> $eventV2account
 * @property-read int|null $event_v2account_count
 * @property-read Member|null $member
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2UserJoin newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2UserJoin newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EventV2UserJoin query()
 *
 * @mixin \Eloquent
 */
#[ObservedBy([EventV2UserJoinObserver::class])]
class EventV2UserJoin extends Model
{
    use HasMember;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'withdraw_type' => EventV2WithdrawTypeEnum::class,
    ];

    public function eventV2Detail(): BelongsTo
    {
        return $this->belongsTo(EventV2Detail::class);
    }

    public function eventV2BonusCode(): HasOne
    {
        return $this->hasOne(EventV2BonusCode::class);
    }

    public function eventV2account(): HasMany
    {
        return $this->hasMany(EventV2Account::class);
    }
}
