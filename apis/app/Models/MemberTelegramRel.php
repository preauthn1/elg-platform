<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $brand_id brand id
 * @property int $tg_user_id tg user_id
 * @property int $member_id 會員系統編號
 * @property string $tg_password password 明文
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property-read Brand|null $brand
 * @property-read Member|null $member
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MemberTelegramRel newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MemberTelegramRel newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MemberTelegramRel query()
 *
 * @mixin \Eloquent
 */
class MemberTelegramRel extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 關聯: 品牌
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id', 'id');
    }

    /**
     * 關聯: 會員
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id', 'id');
    }
}
