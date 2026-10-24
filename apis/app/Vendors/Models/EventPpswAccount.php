<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\EventVendorInfo;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $event_vendor_info_id 活動遊戲商設定編號
 * @property int $member_id 會員編號
 * @property string $username 玩家帳號
 * @property int|null $player_id 遊戲方玩家編號
 * @property string|null $token
 * @property string $currency 幣別
 * @property string $country 幣別
 * @property int $status 狀態 0:關閉,1:正常
 * @property int $admin
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EventVendorInfo|null $eventVendorInfo
 * @property-read Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static Builder<static>|EventPpswAccount newModelQuery()
 * @method static Builder<static>|EventPpswAccount newQuery()
 * @method static Builder<static>|EventPpswAccount query()
 *
 * @mixin \Eloquent
 */
class EventPpswAccount extends AbstractAccount
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
