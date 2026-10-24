<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $bet_id bettransacion_id from request
 * @property string $username 玩家帳號
 * @property string $game_code 遊戲編號
 * @property int $status 0: 未有結果, -1: 取消, 9: 結算
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 真實下注金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chips 使用禮碼
 * @property string $bet_time 下注時間
 * @property string $payout_time 派彩/取消/bonus時間
 * @property string|null $infos 詳細資訊
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnebxswBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnebxswBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OnebxswBetRecord query()
 *
 * @mixin \Eloquent
 */
class OnebxswBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = 'rolling_chips';

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
