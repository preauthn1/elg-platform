<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property string $bet_id
 * @property string|null $reference_bet_id
 * @property string $username
 * @property string $game_code
 * @property int $status
 * @property int $type
 * @property numeric $bet
 * @property numeric $valid_bet
 * @property numeric $win_lose
 * @property numeric $rolling_chips
 * @property string $bet_time
 * @property string $payout_time
 * @property string|null $infos
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VplusswBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VplusswBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VplusswBetRecord query()
 *
 * @mixin \Eloquent
 */
class VplusswBetRecord extends AbstractBetRecord
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
}
