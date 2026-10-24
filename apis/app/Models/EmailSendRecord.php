<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 電子郵件發送紀錄
 *
 * @property int $id 流水號
 * @property int $email_gateway_id 電子郵件提供商ID(email_gateways.id); 0:未設定提供商
 * @property int $agent_id 營運商系統編號(agents.id)
 * @property int $member_id 玩家ID
 * @property string $sender 寄件信箱
 * @property string $receiver 收件信箱
 * @property string|null $subject 郵件主旨
 * @property string|null $message_content 郵件內容
 * @property string|null $response_message 回應內容
 * @property int $status 發送狀態; 0:未發送, 1:發送成功, 2:發送失敗
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|EmailSendRecord newModelQuery()
 * @method static Builder<static>|EmailSendRecord newQuery()
 * @method static Builder<static>|EmailSendRecord query()
 *
 * @mixin \Eloquent
 */
class EmailSendRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
