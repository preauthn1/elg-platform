<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $ref_id 識別碼 {player_id}-{game_id}-{bet_time}-{bet_id]
 * @property string $btype 遊戲分類 (single, live)
 * @property int|null $class_id 未知
 * @property string $player_id 玩家帳號
 * @property string|null $bet_time 投注時間
 * @property string|null $bet_time_utc 投注時間utc
 * @property numeric|null $begin_balance 投注前餘額
 * @property numeric|null $end_balance 結算後餘額
 * @property int|null $game_id 遊戲ID
 * @property string|null $game_name 遊戲名稱
 * @property int|null $line_num 未知
 * @property string|null $log_data_str 遊戲記錄
 * @property int|null $log_data_type 未知
 * @property int|null $round_no 局號
 * @property int|null $row_num 未知
 * @property int|null $table_id 桌號
 * @property numeric|null $win 派彩金額
 * @property numeric|null $bet 投注金額
 * @property int|null $bet_id 未知
 * @property string|null $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $created_by 建立者ID
 * @property int $updated_by 更新者ID
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Mega888BetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Mega888BetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Mega888BetRecord query()
 *
 * @mixin \Eloquent
 */
class Mega888BetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = null;

    // win 為派彩金額，輸贏無單一欄位
    protected ?string $winLoseColumn = null;

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = null;

    protected ?string $betTimeColumn = 'bet_time';

    protected ?string $vendorUsernameColumn = 'player_id';
}
