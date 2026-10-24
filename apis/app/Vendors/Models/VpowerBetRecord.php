<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id 系統編號
 * @property string $uniqleid 注單編號(唯一值)
 * @property string $uid
 * @property string $gameid 遊戲ID
 * @property string $tableid 桌子ID
 * @property string $username 玩家名稱
 * @property numeric $pay 下注金額
 * @property numeric|null $win 派彩金額
 * @property numeric $startscore 遊戲開始金額
 * @property numeric $finalscore 遊戲結束金額
 * @property string $time 時間
 * @property string $utc_time 時間(UTC)
 * @property string|null $origin_utc_time
 * @property string|null $info
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VpowerBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VpowerBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VpowerBetRecord query()
 *
 * @mixin \Eloquent
 */
class VpowerBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'pay';

    protected ?string $validBetColumn = null;

    // win 為派彩金額，輸贏無單一欄位
    protected ?string $winLoseColumn = null;

    protected ?string $winColumn = 'win';

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    // 唯一索引是 (time, uid, uniqleid, startscore, finalscore) 複合鍵，但 VWorld 517 萬列與 TPower 9690 萬列實測 uniqleid 皆無重複
    protected ?string $betIdColumn = 'uniqleid';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $payoutTimeColumn = 'utc_time';

    protected ?string $betTimeColumn = 'utc_time';

    protected ?string $vendorUsernameColumn = 'username';
}
