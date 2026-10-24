<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Member;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Member|null $member
 */
trait HasMember
{
    /**
     * 關聯的 member 帳號
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id')
            ->where('status', 1);
    }
}
