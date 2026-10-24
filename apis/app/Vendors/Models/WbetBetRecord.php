<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Class WbetBetRecord.
 *
 * @property int $id
 * @property string $bet_id 注單唯一Id, ThemeId
 * @property string $vendor_username 遊戲商的玩家帳號名
 * @property string $bet_time 投注時間(UTC)
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 有效投注金額
 * @property numeric $win_lose 輸贏金額
 * @property string|null $payout_time 派彩、結算時間(UTC)
 * @property int $status 狀態 0:未結算,1:已結算
 * @property string $game_code 遊戲 ID-依據遊戲商需求
 * @property numeric $rolling_chip 禮碼金額-依據遊戲商需求
 * @property numeric $jackpot 派彩金额-依據遊戲商需求
 * @property string $session_token 訪問投注單的 Session Token 或 ID 簽章-依據遊戲商需求
 * @property string $info response data
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|WbetBetRecord newModelQuery()
 * @method static Builder<static>|WbetBetRecord newQuery()
 * @method static Builder<static>|WbetBetRecord query()
 * @method static Builder<static>|WbetBetRecord table($tableName)
 *
 * @mixin \Eloquent
 */
class WbetBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = 'rolling_chip';

    protected ?string $jackpotColumn = 'jackpot';

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'wbet_bet_records';

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'vendor_username';

    /**
     * The attributes that are not fillable through mass assignment.
     *
     * @var array<int, string>
     */
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * Use the table($tableName) method to dynamically set the data table.
     */
    public function scopeTable($query, $tableName)
    {
        return $query->from($tableName);
    }
}
