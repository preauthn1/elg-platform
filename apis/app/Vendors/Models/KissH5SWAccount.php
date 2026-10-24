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
 * @property string|null $auth_token 登入遊戲更新
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $status 狀態 0:關閉,1:正常
 * @property int $admin
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static Builder<static>|KissH5SWAccount newModelQuery()
 * @method static Builder<static>|KissH5SWAccount newQuery()
 * @method static Builder<static>|KissH5SWAccount query()
 *
 * @mixin \Eloquent
 */
class KissH5SWAccount extends AbstractAccount
{
    protected $table = 'kissh5sw_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
