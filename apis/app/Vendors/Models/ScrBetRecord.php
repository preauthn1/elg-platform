<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int|null $class_id 未知
 * @property string $account 918玩家帳號
 * @property string|null $bet_time 投注時間
 * @property string|null $bet_time_utc 投注時間utc
 * @property numeric|null $begin_balance 投注前餘額
 * @property numeric|null $end_balance 結算後餘額
 * @property int|null $game_id 遊戲ID
 * @property string|null $game_name 遊戲名稱
 * @property int|null $line_num 未知
 * @property string|null $log_data_str 遊戲記錄
 * @property int|null $log_data_type 1:设置分数, 2:游戏记录, 3:未完成的游戏, 4:JackPot, 5:Double, 6:Game bonus, 8:红包, 13:未完成的纸牌游戏
 * @property int|null $round_no 未知
 * @property int|null $row_num 未知
 * @property int|null $table_id 桌號
 * @property numeric|null $win 輸贏金額
 * @property numeric|null $bet 投注金額
 * @property int|null $cday 未知
 * @property int|null $cno 未知
 * @property string|null $uuid 未知
 * @property string|null $info response data
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScrBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScrBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ScrBetRecord query()
 *
 * @mixin \Eloquent
 */
class ScrBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = null;

    // 輸贏由 getWin() - getBet() 計算得出
    protected ?string $winLoseColumn = null;

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'uuid';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'updated_at';

    protected ?string $betTimeColumn = 'bet_time_utc';

    protected ?string $vendorUsernameColumn = 'account';
}
