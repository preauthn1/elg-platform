<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 流水號
 * @property string $login_id 投注者帳號
 * @property string $bet_id 投注記錄編號
 * @property string $bet_time 投注時間
 * @property string|null $utc_bet_time 投注的UTC時間
 * @property int $draw_type 開彩莊家{0:全部, 1:Magnum, 2: PMP, 3: Toto, 4: Singapore, 5: Sabah, 6: Sandakan, 7: Sarawak, 8: GD (豪龙)}
 * @property string $draw_date 開彩日期
 * @property int $bet_number 投注號碼
 * @property string $bet_position 投注位置
 * @property numeric $bet_amount 投注額
 * @property numeric|null $strike 中獎金額
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HaolongBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HaolongBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|HaolongBetRecord query()
 *
 * @mixin \Eloquent
 */
class HaolongBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet_amount';

    protected ?string $validBetColumn = null;

    // 對齊舊系統 SummaryBetRecordCommand：輸贏取 strike
    protected ?string $winLoseColumn = 'strike';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'draw_date';

    protected ?string $betTimeColumn = 'utc_bet_time';

    protected ?string $vendorUsernameColumn = 'login_id';
}
