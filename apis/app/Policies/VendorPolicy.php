<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;

/**
 * 遊戲商管理授權：Olympus 三種角色（ADMIN／SUPERADMIN／運維）都能檢視與修改全部欄位；
 * 回調 IP 白名單這類安全設定是運維的工作，所以不像帳號管理那樣對運維關閉。
 *
 * 刻意不定義 create／delete，依 Laravel 預設一律拒絕：新增遊戲商走 database/data/vendors.php
 * 升級時自動補齊，刪除以停用取代（歷史注單以 vendor_id 關聯，刪了對不到遊戲商）。
 *
 * @knowledge rules/vendor-and-channel-management
 */
class VendorPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return true;
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return true;
    }
}
