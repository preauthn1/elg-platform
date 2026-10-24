<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $record_id 押注记录 id (全局唯一) - recordId
 * @property string $bet_id 游戏局id - requestId
 * @property int $round_id 当前局的轮次
 * @property string $username 玩家帳號
 * @property string $game_code 游戏ID
 * @property string $bet_time 投注時間(utc)
 * @property numeric $bet 下注金额
 * @property numeric $win_lose 輸贏金额
 * @property string $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SmaBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SmaBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SmaBetRecord query()
 *
 * @mixin \Eloquent
 */
class SmaBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = null;

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    // 表上另有 bet_id，但舊系統以 record_id 去重
    protected ?string $betIdColumn = 'record_id';

    protected $table = 'sma_bet_records';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'updated_at';

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'username';
}
