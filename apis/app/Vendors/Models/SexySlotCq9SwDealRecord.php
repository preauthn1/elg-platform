<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Vendors\SexySlotCq9SW\Enums\BetRecordStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 逐筆 mtcode 帳本，`mtcode` unique 當冪等鍵；amount 為這筆事件原始金額，永不覆寫，
 * 退款靠 `is_refund` 標記，不覆蓋 type/amount。
 *
 * @property int $id
 * @property string $bet_id 注單id（roundid）
 * @property string $mtcode 冪等鍵
 * @property numeric $amount 這筆事件原始金額
 * @property string $username 玩家帳號
 * @property numeric $balance 變動後錢包餘額
 * @property string $game_hall 遊戲廠商代號
 * @property string $game_code 遊戲編號
 * @property BetRecordStatusEnum $type 這筆 mtcode 自己的事件
 * @property bool $is_refund 是否已被退款
 * @property numeric|null $refund_balance 退款後錢包餘額（refund resend 回前次餘額用）
 * @property Carbon $event_time 事件時間
 * @property array|null $info 原始 callback payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|SexySlotCq9SwDealRecord newModelQuery()
 * @method static Builder<static>|SexySlotCq9SwDealRecord newQuery()
 * @method static Builder<static>|SexySlotCq9SwDealRecord query()
 *
 * @mixin \Eloquent
 */
class SexySlotCq9SwDealRecord extends Model
{
    protected $table = 'sexyslotcq9sw_deal_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'type' => BetRecordStatusEnum::class,
        'is_refund' => 'boolean',
        'event_time' => 'datetime',
        'info' => 'array',
    ];
}
