<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QqpkswBetRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QqpkswBetRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|QqpkswBetRecord query()
 *
 * @mixin \Eloquent
 */
class QqpkswBetRecord extends AbstractBetRecord
{
    protected ?string $betColumn = null;

    protected ?string $validBetColumn = null;

    // 無對應資料表
    protected ?string $winLoseColumn = null;

    protected ?string $winColumn = null;

    protected ?string $rollingChipColumn = null;

    protected ?string $jackpotColumn = null;

    protected ?string $bonusColumn = null;

    protected ?string $betIdColumn = null;

    protected ?string $payoutTimeColumn = null;

    protected ?string $betTimeColumn = null;

    protected ?string $vendorUsernameColumn = null;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
