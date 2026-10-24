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
 * @property string|null $auth_token 登入遊戲更新（GameLogin 產生，供 seamless 回調驗證玩家會話）
 * @property Carbon $created_at 建立日期
 * @property Carbon $updated_at 更新日期
 * @property int $status 狀態 0:關閉,1:正常
 * @property int $admin
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static Builder<static>|Slot4dswAccount newModelQuery()
 * @method static Builder<static>|Slot4dswAccount newQuery()
 * @method static Builder<static>|Slot4dswAccount query()
 *
 * @mixin \Eloquent
 */
class Slot4dswAccount extends AbstractAccount
{
    protected $table = 'slot4dsw_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
