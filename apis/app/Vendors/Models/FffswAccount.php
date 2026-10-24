<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\Member;
use App\Models\Traits\HasMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * App\Models\FffswAccount
 *
 * @property int $id
 * @property int $vendor_info_id 遊戲商環境
 * @property int $member_id 我方會員ID
 * @property int $status 狀態 0:關閉,1:正常
 * @property string $username 三方玩家帳號
 * @property string $password 三方玩家密碼
 * @property int $admin 管理員編號
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, FffswBetRecord> $bet_records
 * @property-read int|null $bet_records_count
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static Builder<static>|FffswAccount newModelQuery()
 * @method static Builder<static>|FffswAccount newQuery()
 * @method static Builder<static>|FffswAccount query()
 *
 * @mixin \Eloquent
 */
class FffswAccount extends AbstractAccount
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
        return $this->hasMany(FffswBetRecord::class, 'user', 'user');
    }
}
