<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 流水號
 * @property string $username 用戶名
 * @property string $userId 用戶獨立ID
 * @property string $gameType 遊戲類型
 * @property string $gameName 遊戲名稱
 * @property string $betHistoryId 下注記錄ID,唯一ID
 * @property numeric $bet 投注額
 * @property numeric $validBet 有效投注額
 * @property numeric $payout 派彩
 * @property numeric $balance 盈餘
 * @property numeric $payoutWithoutholding 單純派彩金額 若沒有預扣金額，參數值會和payout相同 若有預扣金額，計算公式為payout – niuniuWithholdingtotal，其參數值大於0是玩家贏錢，等於0是玩家輸錢
 * @property numeric $niuniuWithHoldingTotal 牛牛預扣加總
 * @property string|null $betMap 下注項目
 * @property string|null $roundNo 牌局號
 * @property string|null $payoutDetail 派彩詳情
 * @property string|null $judgeResult 開牌結果
 * @property string|null $playerCards 閒家牌型
 * @property int|null $playerResult 閒家結果
 * @property string|null $bankerCards 庄家牌型
 * @property int|null $bankerResult 庄家結果
 * @property string|null $allDices 骰寶所有骰子
 * @property int|null $dragonCard 龍虎，龍的牌型
 * @property int|null $tigerCard 龍虎，虎的牌型
 * @property int|null $number 輪盤遊戲，結果數字
 * @property string|null $niuniuResult 牛牛牌型比較結果
 * @property string|null $niuniuWithHoldingDetail 牛牛預扣betType細節參數
 * @property int|null $createTime 開始時間
 * @property string|null $utc_create_time utc開始時間
 * @property int|null $payoutTime 結算時間
 * @property string|null $utc_payout_time utc結算時間
 * @property int|null $platform 玩家遊戲平台,-1:other,0:andorid,1:iOS,2:Mobile H5,3:PC H5
 * @property string|null $info
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EbetBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EbetBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EbetBetRecord query()
 *
 * @mixin \Eloquent
 */
class EbetBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'validBet';

    // 對齊舊系統 SummaryBetRecordCommand：輸贏取 balance
    protected ?string $winLoseColumn = 'balance';

    protected ?string $winColumn = 'payout';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'betHistoryId';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'utc_payout_time';

    protected ?string $betTimeColumn = 'utc_create_time';

    protected ?string $vendorUsernameColumn = 'username';
}
