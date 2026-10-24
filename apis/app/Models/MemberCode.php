<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int|null $member_id 會員ID
 * @property string $agent_code 會員推廣碼
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Member|null $member
 *
 * @method static Builder<static>|MemberCode newModelQuery()
 * @method static Builder<static>|MemberCode newQuery()
 * @method static Builder<static>|MemberCode query()
 *
 * @mixin \Eloquent
 */
class MemberCode extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 關聯的 member 帳號
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id')
            ->where('status', 1);
    }
}
