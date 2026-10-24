<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property string $name 名稱
 * @property int $status 狀態
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read Collection<int, Bank> $banks
 * @property-read int|null $banks_count
 * @property-read Collection<int, WithdrawalTypeName> $names
 * @property-read int|null $names_count
 *
 * @method static Builder<static>|WithdrawalType newModelQuery()
 * @method static Builder<static>|WithdrawalType newQuery()
 * @method static Builder<static>|WithdrawalType query()
 *
 * @mixin \Eloquent
 */
class WithdrawalType extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function names()
    {
        return $this->hasMany(WithdrawalTypeName::class, 'withdrawal_type_id', 'id');
    }

    public function banks()
    {
        return $this->hasMany(Bank::class, 'type', 'id')->where('status', 1);
    }
}
