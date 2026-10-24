<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 系統編號
 * @property string $data_type 下注記錄詳情
 * @property string $bill_no 注單流水號
 * @property string $player_name 玩家帳號
 * @property string $agent_code 代理商編號
 * @property string $game_code 遊戲局號
 * @property numeric $net_amount 玩家輸贏金額
 * @property string $bet_time 投注時間
 * @property string|null $utc_bet_time 時區+0的投注時間(AG原始的時區為-4)
 * @property string|null $game_type 遊戲代碼
 * @property numeric $bet_amount 玩家投注金額
 * @property numeric $valid_bet_amount 有效投注金額
 * @property int $flag 結算狀態0:未結算,1:已結算,2:下注中,4:撤單套現,-8:取消指定局注單,-9:取消指定注單
 * @property int $play_type 遊戲玩法
 * @property string|null $currency 貨幣類型
 * @property string $table_code 桌子編號
 * @property string $login_ip 玩家IP
 * @property string $recalcu_time 注單重新派彩時間
 * @property string $utc_recalcu_time 結算時間(UTC時區)
 * @property string $platform_type 平台類型
 * @property string $remark 額外訊息
 * @property string $round 平台內大廳類型
 * @property string $result 結果
 * @property numeric $before_credit 下注前餘額
 * @property int $device_type 設備類型
 * @property int $game_category 0:非電子桌面遊戲,1:電子桌面遊戲
 * @property string $cancel_reason 取消原因
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AgBetRecord query()
 *
 * @mixin \Eloquent
 */
class AgBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet_amount';

    protected ?string $validBetColumn = 'valid_bet_amount';

    protected ?string $winLoseColumn = 'net_amount';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bill_no';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'utc_recalcu_time';

    protected ?string $betTimeColumn = 'utc_bet_time';

    protected ?string $vendorUsernameColumn = 'player_name';
}
