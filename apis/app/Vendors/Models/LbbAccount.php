<?php

declare(strict_types=1);

namespace App\Vendors\Models;

/**
 * @property int $id
 * @property int $vendor_info_id 遊戲商設定編號
 * @property string $username 玩家帳號
 * @property string $username_modified 玩家帳號(含前綴)
 * @property int $member_id 會員系統編號
 * @property int $status 狀態 0:關閉,1:正常
 * @property \Illuminate\Support\Carbon $created_at 建立日期
 * @property \Illuminate\Support\Carbon $updated_at 更新日期
 * @property int $admin
 * @property-read \App\Models\EventVendorInfo|null $eventVendorInfo
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\VendorInfo|null $vendorInfo
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LbbAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LbbAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LbbAccount query()
 *
 * @mixin \Eloquent
 */
class LbbAccount extends AbstractAccount
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected ?string $vendorUsernameColumn = 'username_modified';

    /**
     * i1betnow（target/op_tickets#3264）：`username_modified` 是「ACCESS_ID@我方 username」
     * （組法見 {@see \App\Vendors\I1betnow\Driver::createAccount()}）。ACCESS_ID 是我方的
     * 商戶識別碼、每一筆帳號都相同，對後台人員沒有辨識意義，顯示時去掉。
     *
     * 🔴 只改顯示：登入／轉帳／注單對帳都以完整的 `username_modified` 為鍵
     * （LBB 無密碼機制），這裡回傳的字串禁止寫回 DB 或送給三方。
     *
     * 只切第一個 `@`，我方 username 本身若含 `@` 不會被影響。
     */
    public function displayVendorUsername(string $vendorUsername): string
    {
        $separator = strpos($vendorUsername, '@');

        return $separator === false
            ? $vendorUsername
            : substr($vendorUsername, $separator + 1);
    }
}
