<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 站內信主表 工單4152
 *
 * 與 AnnouncementMessage（群發公告，對象含聊天群組與代理）不同，本表專為
 * 對會員發信而設。四種發送類型分兩種收件人存法：指定會員／會員群組於存檔時
 * 展開進 recipients；全平臺／代理及子代理不展開，查詢時動態比對。
 *
 * @property int $id 流水號
 * @property int $send_type 發送類型 1:指定會員 2:會員群組 3:全平臺(組織) 4:代理及子代理
 * @property int $agent_id 發信者代理ID
 * @property int $organization_id 組織ID(send_type=3 時有值)
 * @property int $hierarchy_agent_id 指定代理ID(send_type=4 時有值)
 * @property array<int>|null $member_group_ids 選定的會員群組ID清單(send_type=2 時有值，僅供顯示與編輯回填)
 * @property Carbon $publish_time 發布時間
 * @property int $status 狀態 0:停用 1:啟用
 * @property int $recipient_count 應收人數(供後台統計觸達率)
 * @property string|null $memo 備註
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|MemberMail newModelQuery()
 * @method static Builder<static>|MemberMail newQuery()
 * @method static Builder<static>|MemberMail query()
 *
 * @mixin \Eloquent
 */
class MemberMail extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'publish_time' => 'datetime',
            'member_group_ids' => 'array',
        ];
    }

    /** 多語系內容，一語系一筆 */
    public function languages(): HasMany
    {
        return $this->hasMany(MemberMailLanguage::class, 'member_mail_id');
    }

    /** 收件人，僅 send_type 為指定會員／會員群組時有資料 */
    public function recipients(): HasMany
    {
        return $this->hasMany(MemberMailRecipient::class, 'member_mail_id');
    }

    /** 已讀紀錄，會員實際讀取才產生 */
    public function reads(): HasMany
    {
        return $this->hasMany(MemberMailRead::class, 'member_mail_id');
    }

    /** 已到發布時間即鎖定，不可再異動內容、對象、狀態，也不可刪除 */
    public function isPublished(): bool
    {
        return $this->publish_time !== null && $this->publish_time->isPast();
    }
}
