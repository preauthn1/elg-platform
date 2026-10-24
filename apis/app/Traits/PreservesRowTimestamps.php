<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Support\Facades\DB;

/**
 * 銀行搬移類寫入的時間戳保護
 *
 * `withdrawal_orders`／`member_bank_accounts`／`realname_validation_requests`／
 * `agent_bank_accounts`／`agent_real_name_validation_requests` 的 `updated_at` 都帶
 * `on update CURRENT_TIMESTAMP`：只寫業務欄位時 MySQL 會把它一併蓋成執行時間。
 *
 * 銀行收斂／改指／正名是「同一筆訂單或綁定換綁到另一個 banks row」的**技術搬移**，
 * 不是業務事件。蓋掉時間戳會污染所有以 `updated_at` 判讀時序的下游——
 * `withdrawal_orders.updated_at` 正是前端訂單排序欄，2026-08-27 因此造成生產訂單順序
 * 混亂（六站 2,927 筆，已由 `bank:restore-fk-timestamps` 還原）。
 *
 * 用法：把要寫的欄位陣列包一層 `$this->preservingUpdatedAt([...])`。
 *
 * ⚠️ **不適用 `banks` 與 `agent_cash_flows`**：那兩張表在收斂中是「該列自身真的被改」
 * （改名／停用／`available_banks` 改寫），`updated_at` 更新是正確語意，抑制反而讓配置
 * 變更無跡可循。
 *
 * ⚠️ 自我賦值的字串是常數、無外部輸入，不屬 CLAUDE.md「未綁定 `DB::raw`」禁令範圍。
 */
trait PreservesRowTimestamps
{
    /**
     * 附加 `updated_at` 自我賦值，抑制 MySQL 的 `on update CURRENT_TIMESTAMP`。
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function preservingUpdatedAt(array $values): array
    {
        $values['updated_at'] = DB::raw('`updated_at`');

        return $values;
    }
}
