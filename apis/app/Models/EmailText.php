<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 驗證碼郵件樣板（形狀比照 SmsText，簡訊以國家分、郵件以語系分）
 *
 * @property int $id 流水號
 * @property int $email_gateway_group_id 電子郵件設置組別ID(agent_groups.id, agent_group_type=7)
 * @property int $type_id 樣板類型; 見 App\Enums\EmailTextTypeEnum
 * @property int $language_id 語系ID(languages.id); 0=全語系預設列
 * @property string $subject 郵件主旨
 * @property string $content 郵件內文(純文字, 佔位符 {code} {email})
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID(agents.id)
 *
 * @method static Builder<static>|EmailText newModelQuery()
 * @method static Builder<static>|EmailText newQuery()
 * @method static Builder<static>|EmailText query()
 *
 * @mixin \Eloquent
 */
class EmailText extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
