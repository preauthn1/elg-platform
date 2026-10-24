<?php

declare(strict_types=1);

namespace App\Vendors\Models;

// php artisan ide-helper:models "App\Vendors\Models\CrswBetRecord"
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * @property int $id
 * @property string $bet_id 注單id
 * @property string $username 玩家帳號
 * @property string $game_code 遊戲編號, gameId from request
 * @property int $result 0:未有結果, 1:輸, 2:贏, 3:和, 4:取消
 * @property int $status 1:PlaceBet, 2:CancelBet, 3:RevertBet, 4:SettleBet, 5:UnsettleBet
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 有效下注金額
 * @property numeric $de_bet 實際扣除金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chips 使用禮碼
 * @property string $bet_time 下注時間
 * @property string|null $result_date 算結果的日期時間
 * @property string $settle_date 注單異動時間
 * @property string $order_date 歸帳日期
 * @property string|null $infos 詳細資訊
 * @property string|null $result_infos result 詳細資訊
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read mixed $payout_time
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CrswBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CrswBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CrswBetRecord query()
 *
 * @mixin \Eloquent
 */
class CrswBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = 'rolling_chips';

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'result_date';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';

    /**
     * 修改器: payout_time
     * ! TotalBetRecordService 需要使用這個欄位來計算派彩時間
     */
    protected function payoutTime(): Attribute
    {
        return Attribute::get(function () {
            return $this->result_date;
        });
    }
}
