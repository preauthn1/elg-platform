<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員系統編號
 * @property string $username 玩家帳號
 * @property string $bet_id 注單 ID
 * @property string $reference_no 三方交易 ID (transactionId)，用於冪等
 * @property string $game_code 遊戲代碼
 * @property string $bet_type 投注類型
 * @property numeric $bet 投注金額
 * @property numeric $valid_bet 有效投注
 * @property numeric $win 派彩金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $bonus 活動獎金
 * @property numeric $jackpot Jackpot 獎金
 * @property numeric $rolling_chip 禮碼金額
 * @property int $is_end_round 是否為該局結束
 * @property string|null $bet_time 投注時間
 * @property string|null $payout_time 派彩時間
 * @property string|null $raw_data 原始請求資料
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RectangleSwBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RectangleSwBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RectangleSwBetRecord query()
 *
 * @mixin \Eloquent
 */
class RectangleSwBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = 'rolling_chip';

    protected ?string $jackpotColumn = 'jackpot';

    protected ?string $bonusColumn = 'bonus';

    // 歷史樣板誤置：本表唯一交易鍵存於 reference_no（bet_id 為回合/非唯一），getBetId() 供 TBR.reference_no 與 log 使用
    protected ?string $betIdColumn = 'reference_no';

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';

    protected $table = 'rectanglesw_bet_records';
}
