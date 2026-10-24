<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property int $member_id 會員系統編號
 * @property string $username Joker playerID 含 prefix
 * @property string|null $password Register Player 設定的密碼（OP/客服登入 Joker portal 用）
 * @property int $status 狀態 0:關閉,1:正常
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JkbswAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JkbswAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|JkbswAccount query()
 *
 * @mixin \Eloquent
 */
class JkbswAccount extends AbstractAccount
{
    protected $table = 'jkbsw_accounts';

    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username';
}
