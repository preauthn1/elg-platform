<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $language_id
 * @property int $withdrawal_type_id
 * @property string $name 名稱
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 *
 * @method static Builder<static>|WithdrawalTypeName newModelQuery()
 * @method static Builder<static>|WithdrawalTypeName newQuery()
 * @method static Builder<static>|WithdrawalTypeName query()
 *
 * @mixin \Eloquent
 */
class WithdrawalTypeName extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
