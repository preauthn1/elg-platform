<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 系統編號
 * @property string $round_id 注單號
 * @property string $game_identity 遊戲識別碼
 * @property string $currency 幣別
 * @property string $wallet_type 區段/錢包類型
 * @property string|null $external_reference 外部編號
 * @property string|null $external_user_id 外部用戶識別
 * @property string|null $user_token 會員授權碼
 * @property string|null $session_token 區段授權碼
 * @property string|null $start_time 注單下注時間
 * @property string|null $end_time 注單結算時間
 * @property string|null $utc_start_time 注單下注時間(UTC+0)
 * @property string|null $utc_end_time 注單結算時間(UTC+0)
 * @property numeric $initial_balance 錢包起始餘額
 * @property numeric|null $finial_balance 錢包最後餘額
 * @property numeric $bet_amount 總投注額
 * @property numeric $win_amount 總贏分
 * @property numeric $jack_pot_bet 彩池投注額
 * @property numeric $jack_pot_win 彩池總贏分
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WmBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WmBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WmBetRecord query()
 *
 * @mixin \Eloquent
 */
class WmBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet_amount';

    protected ?string $validBetColumn = null;

    // win_amount 為總贏分，輸贏無單一欄位
    protected ?string $winLoseColumn = null;

    protected ?string $winColumn = 'win_amount';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'round_id';

    protected ?string $payoutTimeColumn = 'utc_end_time';

    protected ?string $betTimeColumn = 'utc_start_time';

    protected ?string $vendorUsernameColumn = 'external_user_id';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
