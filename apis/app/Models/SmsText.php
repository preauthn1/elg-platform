<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id 流水號
 * @property int $agent_id 營運商系統編號
 * @property int $sms_gateway_group_id 簡訊群組ID(sms_gateway_groups.id)
 * @property int $type_id 類型編號; params.type=sms_text_type
 * @property int $country_id 國家系統編號
 * @property string $text 訊息內容
 * @property int $status 狀態0:禁用1:使用中
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 *
 * @method static Builder<static>|SmsText newModelQuery()
 * @method static Builder<static>|SmsText newQuery()
 * @method static Builder<static>|SmsText query()
 *
 * @mixin \Eloquent
 */
class SmsText extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
