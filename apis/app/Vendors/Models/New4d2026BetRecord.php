<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $username 玩家帳號
 * @property string $bet_id 注單號
 * @property string $game_code 遊戲代碼
 * @property numeric $bet 投注額
 * @property numeric $valid_bet 有效投注額
 * @property numeric $win 派彩金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $bonus 額外獎金/錦標賽獎金
 * @property numeric $jackpot 彩池獎金
 * @property numeric $rolling_chip 投注禮碼金額
 * @property string $bet_time 投注時間
 * @property string|null $payout_time 結算時間
 * @property int $status 狀態 0:建立注單,1:已派彩,2:取消注單
 * @property string|null $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|New4d2026BetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|New4d2026BetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|New4d2026BetRecord query()
 *
 * @mixin \Eloquent
 */
class New4d2026BetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = 'rolling_chip';

    protected ?string $jackpotColumn = 'jackpot';

    protected ?string $bonusColumn = 'bonus';

    protected ?string $betIdColumn = 'bet_id';

    protected $table = 'new4d2026_bet_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';
}
