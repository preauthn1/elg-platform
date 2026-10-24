<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id 會員系統編號
 * @property Carbon $creation_date LiveChat token 建立時間
 * @property int $license_id LiveChat license id
 * @property string $access_token LiveChat access_token
 * @property string $entity_id LiveChat entity_id
 * @property int $expires_in LiveChat token 有效秒數
 * @property string $token_type LiveChat token_type
 * @property string $lc_cid LiveChat cookie __lc_cid
 * @property string $lc_cst LiveChat cookie __lc_cst
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Member|null $member
 *
 * @method static Builder<static>|LiveChatAccount newModelQuery()
 * @method static Builder<static>|LiveChatAccount newQuery()
 * @method static Builder<static>|LiveChatAccount query()
 *
 * @mixin \Eloquent
 */
class LiveChatAccount extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'creation_date' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
