<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\Traits\HasMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * App\Models\PtswAccount
 *
 * @property int $id
 * @property int $vendor_info_id 遊戲商環境
 * @property int $member_id 我方會員ID
 * @property int $status 狀態 0:關閉,1:正常
 * @property string $username 三方玩家帳號
 * @property string|null $token 外部令牌，用於標識玩家的遊戲週期。必須包含kiosk 前綴。
 * @property int $admin 管理員編號
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read PtswBetRecord|null $betRecord
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PtswBetRecord> $bet_records
 * @property-read int|null $bet_records_count
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static Builder<static>|PtswAccount newModelQuery()
 * @method static Builder<static>|PtswAccount newQuery()
 * @method static Builder<static>|PtswAccount query()
 *
 * @mixin \Eloquent
 */
class PtswAccount extends AbstractAccount
{
    use HasMember;

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';

    /**
     * 關聯的 wmlive_bet_records 注單紀錄
     */
    public function bet_records(): HasMany
    {
        return $this->hasMany(PtswBetRecord::class, 'username', 'username');
    }

    /**
     * 關聯: ptsw_deal_records 交易紀錄
     */
    public function betRecord(): BelongsTo
    {
        return $this->belongsTo(PtswBetRecord::class, 'bet_id', 'bet_id');
    }

    /**
     * 隨機生成外部令牌
     */
    public function generateToken($length = 32): string
    {
        return Str::random($length);
    }
}
