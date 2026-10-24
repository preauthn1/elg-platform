<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $language_id 語言系統編號
 * @property int $bank_id 銀行編號
 * @property string $name 銀行名稱
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 *
 * @method static Builder<static>|BankName newModelQuery()
 * @method static Builder<static>|BankName newQuery()
 * @method static Builder<static>|BankName query()
 *
 * @mixin \Eloquent
 */
class BankName extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
