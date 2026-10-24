<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $bet_id 注单唯一Id, ThemeId
 * @property string $data_type 類型; RNG(老虎機), LC(真人), CANCAL(取消單)
 * @property string $username 玩家帳號
 * @property string $game_code 游戏ID
 * @property string $bet_time 投注時間(utc)
 * @property string $end_time 結算時間(utc)
 * @property numeric $bet 下注金额
 * @property numeric $win_lose 輸贏金额
 * @property int $bet_status 注單狀態 0:取消 1:已派彩
 * @property string $info response data
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|PpBetRecord newModelQuery()
 * @method static Builder<static>|PpBetRecord newQuery()
 * @method static Builder<static>|PpBetRecord query()
 *
 * @mixin \Eloquent
 */
class PpBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = null;

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected ?string $payoutTimeColumn = 'end_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
