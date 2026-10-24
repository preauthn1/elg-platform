<?php

declare(strict_types=1);

namespace App\Vendors\Models;

use App\Models\EventVendorInfo;
use App\Models\Member;
use App\Models\VendorInfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class AbstractAccount extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    /**
     * 玩家在遊戲商那邊的帳號欄位。
     *
     * 三方注單帶的、送三方 API 的、代理後台顯示的都是這個值；我方內部帳號另有其欄，
     * 兩者不一定同名（SCR 是 player_id、gw99 是 username_modified）。
     * 對應關係的權威來源是 database/procedures/SP_transfer_bet_record_*.sql 的 JOIN 條件。
     */
    protected ?string $vendorUsernameColumn = null;

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function vendorInfo(): BelongsTo
    {
        return $this->belongsTo(VendorInfo::class, 'vendor_info_id');
    }

    public function eventVendorInfo(): BelongsTo
    {
        return $this->belongsTo(EventVendorInfo::class, 'event_vendor_info_id');
    }

    public function getVendorUsernameColumn(): ?string
    {
        return $this->vendorUsernameColumn;
    }

    /**
     * 代理後台「顯示用」的遊戲商帳號。
     *
     * 預設回原值——絕大多數遊戲商「送 API 的值」與「人看的帳號」是同一個字串。
     * 只有兩者不同時（例如欄位裡混了我方的商戶識別碼）才由該 model 覆寫。
     *
     * 🔴 覆寫**只能改顯示**：送三方 API、登入／轉帳、注單對帳一律仍用
     * `$vendorUsernameColumn` 的原值，禁止在這裡回傳會被寫回 DB 或送出去的字串。
     */
    public function displayVendorUsername(string $vendorUsername): string
    {
        return $vendorUsername;
    }
}
