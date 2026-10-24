<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $betId 注单唯一Id
 * @property string $userName 玩家帳號
 * @property int $GameId 游戏Id
 * @property string|null $betTimeUtc 押注時間 UTC
 * @property string|null $calTimeUtc 结算时间 UTC
 * @property numeric $winOrLoss 派彩金额 (输赢应扣除下注金额)
 * @property numeric $betPoints 下注金额
 * @property numeric $availableBet 有效下注金额
 * @property int $isRevocation 是否结算：0:未结算, 1:已结算, 2:已撤销(该注单为对冲注单)
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CtBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CtBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CtBetRecord query()
 *
 * @mixin \Eloquent
 */
class CtBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'betPoints';

    protected ?string $validBetColumn = 'availableBet';

    // winOrLoss 為派彩金額，輸贏無單一欄位
    protected ?string $winLoseColumn = null;

    protected ?string $winColumn = 'winOrLoss';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'betId';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'calTimeUtc';

    protected ?string $betTimeColumn = 'betTimeUtc';

    protected ?string $vendorUsernameColumn = 'userName';
}
