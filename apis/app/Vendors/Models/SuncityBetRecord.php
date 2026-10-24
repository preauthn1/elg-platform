<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號（拉單不填，歸戶走 username → suncity_accounts）
 * @property int $member_id 會員系統編號（拉單不填，歸戶走 username → suncity_accounts）
 * @property string $username 玩家帳號（三方 UserName）
 * @property string $bet_id 注單號（三方 GameSerialID），冪等鍵
 * @property string $game_code 遊戲代碼（ThemeID 前四碼 GameID，對應 games.game_type）
 * @property numeric $bet 下注金額（BetCoin）
 * @property numeric $valid_bet 有效投注（AvailTotalBet）
 * @property numeric $win 派彩金額（WinAmount）
 * @property numeric $win_lose 輸贏金額（WinAmount - BetCoin）
 * @property numeric $rolling_chip 禮碼金額
 * @property \Illuminate\Support\Carbon $bet_time 投注時間（UTC）
 * @property \Illuminate\Support\Carbon|null $payout_time 派彩時間（UTC）
 * @property int $status 注單狀態 0:建立中 1:已結算 2:取消/回滾
 * @property string|null $info 三方原始注單
 * @property \Illuminate\Support\Carbon|null $created_at 建立日期
 * @property \Illuminate\Support\Carbon|null $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SuncityBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SuncityBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SuncityBetRecord query()
 *
 * @mixin \Eloquent
 */
class SuncityBetRecord extends AbstractBetRecord
{
    protected $table = 'suncity_bet_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = 'rolling_chip';

    /** 三方注單無 jackpot 欄位，表上未建；null = 無單一欄位可 SUM */
    protected ?string $jackpotColumn = null;

    /** 三方注單無 bonus 欄位，表上未建；null = 無單一欄位可 SUM */
    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';
}
