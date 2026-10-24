<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員編號
 * @property string $username 玩家帳號
 * @property int|null $bet_record_id FK sexyslotsw_bet_records.id
 * @property string $bet_id vendor roundId
 * @property string $reference_no vendor transactionId（冪等鍵）
 * @property string $action vendor action (debit/credit)
 * @property numeric $amount 交易金額
 * @property numeric|null $balance_before 交易前餘額
 * @property numeric|null $balance_after 交易後餘額
 * @property bool $rollback 是否為退款
 * @property bool $endround 是否為回合結束
 * @property array|null $info 原始 callback payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|SexyslotswDealRecord newModelQuery()
 * @method static Builder<static>|SexyslotswDealRecord newQuery()
 * @method static Builder<static>|SexyslotswDealRecord query()
 *
 * @mixin \Eloquent
 */
class SexyslotswDealRecord extends Model
{
    protected $table = 'sexyslotsw_deal_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'info' => 'array',
        'rollback' => 'boolean',
        'endround' => 'boolean',
    ];
}
