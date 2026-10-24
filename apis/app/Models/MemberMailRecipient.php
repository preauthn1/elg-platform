<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 站內信收件人 工單4152
 *
 * 僅 send_type=1（指定會員）與 2（會員群組）於存檔時展開寫入。收件人一旦
 * 寫入即固定，會員之後轉出群組仍收得到已寄出的信。
 *
 * @property int $id 流水號
 * @property int $member_mail_id 站內信ID
 * @property int $member_id 收件會員ID
 * @property Carbon $created_at 建立日期
 *
 * @method static Builder<static>|MemberMailRecipient newModelQuery()
 * @method static Builder<static>|MemberMailRecipient newQuery()
 * @method static Builder<static>|MemberMailRecipient query()
 *
 * @mixin \Eloquent
 */
class MemberMailRecipient extends Model
{
    public $timestamps = false;

    protected $guarded = [
        'id',
        'created_at',
    ];

    public function mail(): BelongsTo
    {
        return $this->belongsTo(MemberMail::class, 'member_mail_id');
    }

    /** 供後台顯示收件人帳號 */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
