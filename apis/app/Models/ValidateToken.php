<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id 系統編號
 * @property int $agent_id agents.id
 * @property int $member_id 會員系統編號(members)
 * @property int $type_id 類型(十位數-1:資料驗證, 2:忘記密碼, 3:找回密碼, 4:洗分驗證, 5:支付密碼, 6:忘記支付密碼, 7:洗分驗證, 8:一頁註冊,9:變更手機或email，個位數-1:手機2:Email)
 * @property string|null $info 驗證的資料
 * @property string|null $info_2 其他資訊
 * @property string $token Token
 * @property string $expire_date 過期日期
 * @property int $status 狀態
 * @property int $times 簡訊發送次數
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin 更新者ID
 * @property-read Agent|null $agent
 * @property-read Member|null $member
 * @property-read mixed $type
 * @property-read mixed $verify_by
 *
 * @method static Builder<static>|ValidateToken newModelQuery()
 * @method static Builder<static>|ValidateToken newQuery()
 * @method static Builder<static>|ValidateToken query()
 *
 * @mixin \Eloquent
 */
class ValidateToken extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * 修改器: type
     */
    protected function type(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) substr((string) $this->type_id, 0, -1)
        );
    }

    /**
     * 修改器: verify_by
     */
    protected function verifyBy(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) substr((string) $this->type_id, -1)
        );
    }
}
