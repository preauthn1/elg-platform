<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CashFlow;
use App\Models\User;

/**
 * 金流商管理授權：Olympus 三種角色（ADMIN／SUPERADMIN／運維）都能檢視與修改白名單；
 * 回調 IP 白名單是運維的工作，所以不對運維關閉（與 {@see VendorPolicy} 同一個判斷）。
 *
 * 刻意不定義 create／delete，依 Laravel 預設一律拒絕：cash_flows 由 `cash-flow:sync` 從
 * `Data/cash_flows.php` 建立，id 被訂單、代理設定等多處引用，不可在後台新增或刪除。
 */
class CashFlowPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CashFlow $cashFlow): bool
    {
        return true;
    }

    public function update(User $user, CashFlow $cashFlow): bool
    {
        return true;
    }
}
