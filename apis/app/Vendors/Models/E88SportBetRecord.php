<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員系統編號
 * @property string $username 玩家帳號
 * @property string $bet_id 注單號
 * @property string $reference_no 三方訂單號
 * @property string $game_code 遊戲代碼
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 有效投注
 * @property numeric $win 派彩金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $bonus 活動獎金
 * @property numeric $jackpot Jackpot 獎金
 * @property numeric $rolling_chip 禮碼金額
 * @property string $bet_time 投注時間
 * @property string|null $payout_time 派彩時間
 * @property int $status 注單狀態 0:建立中 1:已結算 2:取消/回滾
 * @property string|null $info 原始請求資料
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|E88SportBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|E88SportBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|E88SportBetRecord query()
 *
 * @mixin \Eloquent
 */
class E88SportBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = 'rolling_chip';

    protected ?string $jackpotColumn = 'jackpot';

    protected ?string $bonusColumn = 'bonus';

    protected ?string $betIdColumn = 'bet_id';

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';

    protected $table = 'e88sport_bet_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
