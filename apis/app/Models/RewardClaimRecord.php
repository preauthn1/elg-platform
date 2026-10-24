<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id 會員系統編號
 * @property int $event_detail_id event_details.id
 * @property int $type 儲存類型;1:真實姓名、2:註冊IP、3:手機號碼、4:領獎IP、5:Telegram ID
 * @property string $value 儲存值
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 *
 * @method static Builder<static>|RewardClaimRecord newModelQuery()
 * @method static Builder<static>|RewardClaimRecord newQuery()
 * @method static Builder<static>|RewardClaimRecord query()
 *
 * @mixin \Eloquent
 */
class RewardClaimRecord extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
