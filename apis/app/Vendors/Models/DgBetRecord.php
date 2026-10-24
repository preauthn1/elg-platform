<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $betId 注单唯一Id
 * @property string $userName 玩家帳號
 * @property int $lobbyId 游戏大厅号3和4:现场厅, 5:南海厅 , 7:国际厅
 * @property int $tableId 游戏桌号
 * @property int $shoeId 游戏靴号
 * @property int $playId 游戏局号
 * @property int $GameType 游戏类型
 * @property int $GameId 游戏Id
 * @property int $memberId 会员Id
 * @property string $betTime 押注時間
 * @property string $calTime 结算时间
 * @property string $betTimeUtc 押注時間 UTC
 * @property string $calTimeUtc 结算时间 UTC
 * @property numeric $winOrLoss 派彩金额 (输赢应扣除下注金额)
 * @property numeric $betPoints 下注金额
 * @property numeric $betPointsz 好路追注金额
 * @property numeric $availableBet 有效下注金额
 * @property string $result 游戏结果
 * @property string $betDetail 下注注单
 * @property string $ip 游戏结果
 * @property string $ext 游戏唯一ID
 * @property int $isRevocation 是否结算：0:未结算, 1:已结算, 2:已撤销(该注单为对冲注单)
 * @property numeric $balanceBefore 余额
 * @property int $parentBetId 货币ID
 * @property int $currencyId 撤销的那比注单的ID
 * @property int $deviceType 下注时客户端类型
 * @property int $pluginid 追注转账流水号
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DgBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DgBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DgBetRecord query()
 *
 * @mixin \Eloquent
 */
class DgBetRecord extends AbstractBetRecord
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
