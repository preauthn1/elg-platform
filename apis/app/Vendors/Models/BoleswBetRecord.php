<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $bet_id ticketNum from request
 * @property string|null $reference_bet_id 關聯交易 id, ticketId
 * @property string $username 玩家帳號
 * @property string $game_code 遊戲編號, gameId from request
 * @property int $type 類型, 10=上分 11=上分失败回滚 20=下分
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 有效下注金額
 * @property numeric $win 派彩金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chip 使用禮碼
 * @property string $bet_time 下注時間
 * @property string|null $payout_time 派彩/取消/bonus時間
 * @property string|null $info 詳細資訊
 * @property int $settle_status 結算狀態 0=未結算 1=結算成功 2=結算失敗
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BoleswBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BoleswBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|BoleswBetRecord query()
 *
 * @mixin \Eloquent
 */
class BoleswBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = 'rolling_chip';

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
