<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Vendors\SpadegamingSW\Enums\TransferTypeEnum;

/**
 * @property int $id
 * @property string $bet_id 交易 id
 * @property string|null $reference_bet_id 關聯交易 id
 * @property string $username 玩家帳號
 * @property string $game_code 遊戲編號
 * @property TransferTypeEnum $type 1: 下注, 2: 取消下注, 4: 派彩, 7: 紅包
 * @property numeric $bet 下注金額
 * @property numeric $valid_bet 合法下注金額
 * @property numeric $win_lose 輸贏金額
 * @property numeric $rolling_chips 使用禮碼
 * @property string $bet_time 下注時間
 * @property string $payout_time 派彩/取消/bonus時間
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $infos 詳細資訊
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpadegamingswBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpadegamingswBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SpadegamingswBetRecord query()
 *
 * @mixin \Eloquent
 */
class SpadegamingswBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = 'bet';

    protected ?string $validBetColumn = 'valid_bet';

    protected ?string $winLoseColumn = 'win_lose';

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = 'rolling_chips';

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = 'bet_id';

    protected ?string $payoutTimeColumn = 'payout_time';

    protected ?string $betTimeColumn = 'bet_time';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';

    protected $casts = [
        'type' => TransferTypeEnum::class,
    ];
}
