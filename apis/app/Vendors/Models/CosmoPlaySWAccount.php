<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property string $username 玩家帳號（對應 CosmoPlay PlayerID = partner_code-username）
 * @property int $member_id 會員系統編號
 * @property string $session_token 登入遊戲更新的 AuthToken
 * @property int $status 狀態 0:關閉,1:正常
 * @property int $admin
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static Builder<static>|CosmoPlaySWAccount newModelQuery()
 * @method static Builder<static>|CosmoPlaySWAccount newQuery()
 * @method static Builder<static>|CosmoPlaySWAccount query()
 *
 * @mixin \Eloquent
 */
class CosmoPlaySWAccount extends AbstractAccount
{
    protected $table = 'cosmoplaysw_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
