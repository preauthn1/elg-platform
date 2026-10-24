<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 系統編號
 * @property int $class_id ClassID
 * @property string $account pussy888玩家帳號
 * @property string $bet_time 注單生成時間
 * @property string $bet_time_utc 注單生成時間(UTC)
 * @property numeric $begin_balance 遊戲開始金額
 * @property numeric $end_balance 遊戲結束金額
 * @property int $game_id 遊戲ID
 * @property string $game_name 遊戲名稱
 * @property int $line_num
 * @property string $log_data_str 遊戲紀錄
 * @property int $log_data_type
 * @property int $round_no
 * @property int $row_num
 * @property int $table_id 桌號
 * @property numeric $win 派彩金額
 * @property numeric $bet 下注金額
 * @property int $cday
 * @property int $cno
 * @property string $uuid uuid
 * @property string|null $info 詳細信息
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Pussy888BetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Pussy888BetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Pussy888BetRecord query()
 *
 * @mixin \Eloquent
 */
class Pussy888BetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = null;

    // win 為派彩金額，輸贏無單一欄位
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
