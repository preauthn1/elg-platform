<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $register_limit_id 所屬註冊限制ID
 * @property string $value 限制的具體值
 * @property int $max_limit 此值的最高限額
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|AgentRegisterLimitValue newModelQuery()
 * @method static Builder<static>|AgentRegisterLimitValue newQuery()
 * @method static Builder<static>|AgentRegisterLimitValue query()
 *
 * @mixin \Eloquent
 */
class AgentRegisterLimitValue extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function registerLimit(): BelongsTo
    {
        return $this->belongsTo(AgentRegisterLimit::class, 'register_limit_id', 'id');
    }
}
