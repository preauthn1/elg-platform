<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 預付網（OTA）訂單。
 *
 * @property int $id
 * @property string $reference_number 交易編號
 * @property int $member_id 會員系統編號
 * @property string $token Token
 * @property int $agent_cash_flow_id 金流商戶資料編號
 * @property numeric $amount 交易金額
 * @property int $status 狀態 0:訂單建立中,1:訂單建立完成,2:成功,3:失敗
 * @property string $info response data
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read Member|null $member
 *
 * @method static Builder<static>|OtaOrder newModelQuery()
 * @method static Builder<static>|OtaOrder newQuery()
 * @method static Builder<static>|OtaOrder query()
 *
 * @mixin \Eloquent
 */
class OtaOrder extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
