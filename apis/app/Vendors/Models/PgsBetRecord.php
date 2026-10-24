<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $username 玩家帳號
 * @property int $gameID 遊戲代號
 * @property numeric $beforeAmount 押注前分數
 * @property numeric $betAmount 押注分數
 * @property numeric $payoutAmount 派彩分數
 * @property numeric $winLossAmount 輸贏分數
 * @property numeric $validAmount 有效流水
 * @property int $roundID 進房代號
 * @property int $betID 注單代號
 * @property string $betTime 押注時間 UTC
 * @property string $roundStartTime 進房時間
 * @property string $roundEndTime 退房時間
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgsBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgsBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PgsBetRecord query()
 *
 * @mixin \Eloquent
 */
class PgsBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'betAmount';

    protected ?string $validBetColumn = 'validAmount';

    protected ?string $winLoseColumn = 'winLossAmount';

    protected ?string $winColumn = 'payoutAmount';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'betID';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'roundEndTime';

    protected ?string $betTimeColumn = 'betTime';

    protected ?string $vendorUsernameColumn = 'username';
}
