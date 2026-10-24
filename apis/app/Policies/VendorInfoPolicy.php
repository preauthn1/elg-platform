<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VendorInfo;

/**
 * 遊戲商商戶設定（vendor_infos）授權：檢視、新增、修改維持三種角色都可進入。
 *
 * 禁止刪除（delete／deleteAny 一律拒絕）：vendor_infos 存的是三方憑證，VendorInfo 沒有軟刪除，
 * 刪了就是硬刪除、也不留操作紀錄，刪錯只能從 DB 備份救回。
 *
 * 不再使用的商戶設定改用停用（status=0），停用的好處只有「可以復原」，對轉帳的影響與刪除相同：
 * VendorService 查商戶時把 status≠1 與不存在一視同仁，商戶清單快取（約 1 小時，無主動清除）過期後，
 * 代理鏈會改用鏈上其他代理的商戶，或找不到商戶而無法進遊戲／轉帳；舊商戶帳號裡的會員餘額與
 * 結果未知的轉帳單沒有自動處理流程。停用前要先確認這些影響。
 */
class VendorInfoPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, VendorInfo $vendorInfo): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, VendorInfo $vendorInfo): bool
    {
        return true;
    }

    public function delete(User $user, VendorInfo $vendorInfo): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
