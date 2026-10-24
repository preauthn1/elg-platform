<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 站內信已讀紀錄 工單4152
 *
 * 逐封記錄，與平台公告的 last_read_time 單一時間戳不同：點開一封不會讓
 * 其餘信件一併變成已讀。後台的觸達率即以本表筆數除以 recipient_count。
 *
 * @property int $id 流水號
 * @property int $member_mail_id 站內信ID
 * @property int $member_id 已讀會員ID
 * @property Carbon $read_at 已讀時間
 *
 * @method static Builder<static>|MemberMailRead newModelQuery()
 * @method static Builder<static>|MemberMailRead newQuery()
 * @method static Builder<static>|MemberMailRead query()
 *
 * @mixin \Eloquent
 */
class MemberMailRead extends Model
{
    public $timestamps = false;

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function mail(): BelongsTo
    {
        return $this->belongsTo(MemberMail::class, 'member_mail_id');
    }
}
