<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 站內信多語系內容 工單4152
 *
 * 形狀比照 PlatformAnnouncementLanguage（最新消息）。前台取當前語系，
 * 未填則 fallback 到英文——英文於後台為必填，故 fallback 必有內容。
 *
 * @property int $id 流水號
 * @property int $member_mail_id 站內信ID
 * @property int $language_id 語系ID
 * @property string $title 標題
 * @property string $content 內容
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|MemberMailLanguage newModelQuery()
 * @method static Builder<static>|MemberMailLanguage newQuery()
 * @method static Builder<static>|MemberMailLanguage query()
 *
 * @mixin \Eloquent
 */
class MemberMailLanguage extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function mail(): BelongsTo
    {
        return $this->belongsTo(MemberMail::class, 'member_mail_id');
    }
}
