<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string|null $vendor_id
 * @property int $vendor_info_id 遊戲商資訊ID
 * @property string $bet_id 注单唯一Id, ThemeId
 * @property string $username 玩家帳號
 * @property string $game_code 游戏ID
 * @property string $winlost_datetime 算帳時間
 * @property string $bet_time 派彩時間(utc)
 * @property string $created_time 押注時間(utc)
 * @property string|null $ticket_status
 * @property int|null $version_key
 * @property int|null $last_version_key
 * @property numeric $bet 下注金额
 * @property numeric $win_lose 輸贏金额
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SabaBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SabaBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SabaBetRecord query()
 *
 * @mixin \Eloquent
 */
class SabaBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = null;

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected ?string $payoutTimeColumn = 'bet_time';

    protected ?string $betTimeColumn = 'created_time';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
