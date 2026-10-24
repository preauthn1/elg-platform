<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\Member;
use App\Models\VendorInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員系統編號
 * @property string $username 玩家帳號
 * @property string|null $auth_token Auth Token
 * @property string|null $session_token Session Token
 * @property int $status 狀態 0:關閉,1:正常
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read Member|null $member
 * @property-read VendorInfo|null $vendorInfo
 *
 * @method static Builder<static>|TTHSWAccount newModelQuery()
 * @method static Builder<static>|TTHSWAccount newQuery()
 * @method static Builder<static>|TTHSWAccount query()
 *
 * @mixin \Eloquent
 */
class TTHSWAccount extends AbstractAccount
{
    protected $table = 'tthsw_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
