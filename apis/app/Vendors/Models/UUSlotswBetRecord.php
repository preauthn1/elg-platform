<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $bet_id 交易 id
 * @property string|null $reference_bet_id game result trans id
 * @property string $username 玩家帳號
 * @property string $game_code 遊戲編號
 * @property string $status bet: 下注, result: 派彩, rollback: 取消下注, bonus: 紅包, jackpot: 大獎
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 合法下注金額
 * @property numeric $payout 贏得金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chips 使用禮碼
 * @property string $bet_time 下注時間
 * @property string $payout_time 派彩/取消/bonus時間
 * @property string|null $infos 詳細資訊
 * @property string|null $result_infos game result 詳細資訊
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UUSlotswBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UUSlotswBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UUSlotswBetRecord query()
 *
 * @mixin \Eloquent
 */
class UUSlotswBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'payout';

    protected ?string $rollingChipColumn = 'rolling_chips';

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected $table = 'uuslotsw_bet_records';

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
