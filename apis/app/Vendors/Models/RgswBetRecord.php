<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Vendors\RGSW\Enums\BetRecordStatusEnum;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員系統編號
 * @property string $username 玩家帳號
 * @property string $bet_id 注單編號
 * @property string $reference_no 交易編號（唯一）
 * @property string $game_code 遊戲代碼
 * @property string $game_type 遊戲類型
 * @property BetRecordStatusEnum $status
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 有效投注
 * @property numeric $win_lose 輸贏金額
 * @property numeric $win 派彩金額
 * @property numeric $bonus 活動獎金
 * @property numeric $jackpot 彩金
 * @property numeric $rebate 返水
 * @property numeric $rolling_chip 使用禮碼
 * @property string|null $bet_time 下注時間
 * @property string|null $payout_time 派彩時間
 * @property string|null $info 原始請求
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin \Eloquent
 */
class RgswBetRecord extends AbstractBetRecord
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

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';

    protected $casts = [
        'status' => BetRecordStatusEnum::class,
    ];
}
