<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員系統編號
 * @property string $username 玩家帳號
 * @property string|null $session_token 遊戲 session token
 * @property int $status 狀態 0:關閉,1:正常
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RectangleSwAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RectangleSwAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RectangleSwAccount query()
 *
 * @mixin \Eloquent
 */
class RectangleSwAccount extends AbstractAccount
{
    protected $table = 'rectanglesw_accounts';

    protected ?string $vendorUsernameColumn = 'username';
}
