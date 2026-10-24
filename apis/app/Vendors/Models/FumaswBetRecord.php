<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $bet_id transferId from request
 * @property string $round_id roundId, ticketId
 * @property string $username 玩家帳號
 * @property string $game_code 遊戲編號, gameId from request
 * @property string $type 類別, 1: bet, 2: settle, 3: free settle
 * @property int $status 僅用來記錄投注單狀態, 派彩單不受影響, 0: 未派彩, 1: 已派彩, 2: 已退款（bet-record-recovery:refund-unsettled 寫入，對齊 BetRecordStatusEnum::REFUND）
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 真實下注金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chips 使用禮碼
 * @property string $bet_time 下注時間
 * @property string $payout_time 派彩/取消/bonus時間
 * @property string|null $infos 詳細資訊
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property int $is_free_bet
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FumaswBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FumaswBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FumaswBetRecord query()
 *
 * @mixin \Eloquent
 */
class FumaswBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = 'rolling_chips';

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';
}
