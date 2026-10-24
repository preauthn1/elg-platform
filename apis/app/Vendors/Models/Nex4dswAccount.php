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
 * @property int $member_id 會員編號
 * @property string $username 玩家帳號
 * @property string|null $token
 * @property int $status 狀態 0:關閉,1:正常
 * @property int $admin
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read Member|null $member
 * @property-read VendorInfo|null $vendorInfo
 *
 * @method static Builder<static>|Nex4dswAccount newModelQuery()
 * @method static Builder<static>|Nex4dswAccount newQuery()
 * @method static Builder<static>|Nex4dswAccount query()
 *
 * @mixin \Eloquent
 */
class Nex4dswAccount extends AbstractAccount
{
    protected ?string $vendorUsernameColumn = 'username';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];
}
