<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property string $username 玩家帳號
 * @property int $member_id 會員系統編號
 * @property string|null $session_token GameLogin AuthToken，供 GetBalance/Bet 回調驗證
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $status 狀態 0:關閉,1:正常
 * @property int $admin
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static Builder<static>|MetaGamingSWAccount newModelQuery()
 * @method static Builder<static>|MetaGamingSWAccount newQuery()
 * @method static Builder<static>|MetaGamingSWAccount query()
 *
 * @mixin \Eloquent
 */
class MetaGamingSWAccount extends AbstractAccount
{
    protected $table = 'metagamingsw_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
