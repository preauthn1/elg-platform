<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $sms_gateway_id 簡訊商ID(sms_gateways.id)
 * @property int $agent_id agents.id
 * @property int $member_id 玩家ID
 * @property string $mobile 發送號碼
 * @property string $message_content 簡訊發送內容
 * @property string|null $transaction_id 簡訊商發送ID
 * @property string|null $response_message 簡訊商response data(json)
 * @property int $status 發送狀態;0:未發送,1:發送成功,2:發送失敗
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property string $cost 發送當下凍結的手續費，取自挑中那列 sms_gateway_infos.cost（target/tickets#4424）
 * @property int $sms_gateway_info_id 實際套用的 sms_gateway_infos.id；0 代表未知（欄位上線前的歷史列）
 *
 * @method static Builder<static>|SmsSendRecord newModelQuery()
 * @method static Builder<static>|SmsSendRecord newQuery()
 * @method static Builder<static>|SmsSendRecord query()
 *
 * @mixin \Eloquent
 */
class SmsSendRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
