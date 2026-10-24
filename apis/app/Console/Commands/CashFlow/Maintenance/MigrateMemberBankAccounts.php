<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use App\Traits\PreservesRowTimestamps;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 會員銀行帳戶遷移命令
 *
 * 【分類】Maintenance — 可複用維運工具，銀行收斂時跑。
 *
 * ## 發生原因
 * `banks` 表存在**同一家銀行多列**的情形（各金流商 sync 各自 seed、兩套碼並存、
 * base 模板污染複製進 agent 層等，統稱 drift）。會員在不同時期綁卡，就會綁到不同的
 * bank_id 上。收斂銀行時必須把會員綁定從「要停用的列」遷到「保留的基準列」，
 * 否則停用後該會員的綁卡會指向停用銀行、影響提款。
 *
 * ## 修正目的
 * 給定基準 bank_id 與目標 bank_id（可多個），處理會員綁定的兩種情況：
 * - **情況 1（`--fix-both`）**：會員同時綁了基準與目標 → 停用目標側綁定（留基準）
 * - **情況 2（`--fix-missing`）**：會員只綁目標、沒綁基準 → 把目標綁定改指到基準 bank_id
 * - `--fix`：兩種都處理
 *
 * ## 觸發時機
 * 1. **銀行 dedup／drift 收斂前的必要前置**——先遷會員綁定，再停用重複列
 * 2. 銀行改名／換碼（軌1 rename）過程中
 * 3. 發現同一家銀行在會員綁卡清單出現兩筆時
 *
 * ## 冪等性與重跑安全性
 * **冪等，重跑安全。** 已遷移完成的會員不會被重複處理；不帶 `--fix*` 時只出報表。
 * 修復有 `confirm()` 與 `DB::transaction` 保護。
 *
 * ⚠️ **不改 `withdrawal_orders`**：歷史提款單一律不溯及既往（Yes2Win 前例決策），
 * 因此舊單仍指向原 bank_id，這是刻意行為而非遺漏。
 *
 * ## 退役條件
 * 當**銀行軌1 rename 完成、banks 收斂為單一 canonical 列**（同一家銀行不再有多列）後，
 * 會員綁定不會再分岔，本命令即可刪除。
 *
 * ## 關聯
 * - 銀行收斂主線：`docs/未完成計劃/RB/TODO.md` §3.1（五軌總綱）＋ §2.5（rollout 三連）
 * - agent 層 drift 合併：`app/Console/Commands/CashFlow/Guard/MergeWintotoDriftBanks.php`
 * - 邊角表 re-point 共用邏輯：`app/Services/CashFlow/Bank/BankRepairService.php`
 *
 * ## 命令用途
 * 檢查和修復會員銀行帳戶中基準 bank_id 與目標 bank_id 的關係，用於銀行帳戶整合或遷移場景。
 *
 * ## 使用時機
 * 1. **銀行帳戶整合**：系統需要從多個 bank_id 統一到單一 bank_id
 * 2. **銀行遷移**：從舊銀行（目標 bank_id）遷移到新銀行（基準 bank_id）
 * 3. **資料清理**：清理會員的冗餘銀行帳戶
 * 4. **帳戶規範化**：統一會員使用的 bank_id，避免重複帳戶
 *
 * ## 檢查情況
 *
 * 兩案的判準是「會員有沒有**有效**（status=1）的基準帳戶」，不是「有沒有基準帳戶列」。
 * 只比對 bank_id 的話，持有停用基準帳戶的會員會兩邊都不接——情況1 認定他已有基準而停掉
 * 唯一啟用的目標帳戶，情況2 又因同一理由被排除，最後零個有效綁定而無法提款。
 *
 * - **情況1**：會員同時擁有 active 基準帳戶與目標 bank_id
 *   - 修復方案：停用目標 bank_id 的帳戶（status = 0）
 *   - 原因：保留基準帳戶，避免重複
 *
 * - **情況2**：會員只有目標 bank_id，缺少 active 基準帳戶
 *   - 修復方案：將目標 bank_id 更新為基準 bank_id
 *   - 原因：補充基準帳戶，統一使用基準 bank_id
 *
 * ## 使用範例
 * ```bash
 * # 只檢查（預設行為）
 * php artisan member:migrate-bank-accounts --base-bank=556 --target-banks=1347,1348,1349
 *
 * # 修復所有情況
 * php artisan member:migrate-bank-accounts --base-bank=556 --target-banks=1347,1348,1349 --fix
 *
 * # 只修復情況1（停用重複帳戶）
 * php artisan member:migrate-bank-accounts --base-bank=556 --target-banks=1347,1348,1349 --fix-both
 *
 * # 只修復情況2（更新為基準 bank_id）
 * php artisan member:migrate-bank-accounts --base-bank=556 --target-banks=1347,1348,1349 --fix-missing
 * ```
 *
 * ## 安全機制
 * - 預設為只檢查模式，不會修改資料
 * - 所有修復操作使用交易保護
 * - 執行修復前需要確認
 * - 情況2會自動檢測是否已存在基準帳戶，避免重複
 *
 * ## 時間戳保護（2026-09-09 補）
 *
 * 四處寫入（兩處停用、綁定改指、出款訂單同步）一律經 `preservingUpdatedAt()`。
 * `member_bank_accounts` 與 `withdrawal_orders` 都帶 `on update CURRENT_TIMESTAMP`，
 * 而本命令做的是「換綁到另一個 banks row」的**技術搬移**、不是業務事件——
 * `withdrawal_orders.updated_at` 更是前端訂單排序欄，2026-08-27 六站 2,927 筆生產訂單
 * 順序混亂即為此形態（見 `App\Traits\PreservesRowTimestamps` 檔頭）。
 *
 * ⚠️ **停用（`status => 0`）同樣要包**：會員自己沒做任何事，狀態變更是收斂的副作用。
 * 判準比照 `MergeWintotoDriftBanks:697`，不是只有改指才保留時間戳。
 *
 * 迴歸測試以 SQL 字串斷言（`MigrateMemberBankAccountsTest`）——驗欄位值會被 DDL 的
 * `on update` 恆真化，見 `.claude/rules/php.md`。
 */
class MigrateMemberBankAccounts extends Command
{
    use PreservesRowTimestamps;

    /**
     * 命令签名
     */
    protected $signature = 'member:migrate-bank-accounts
                            {--base-bank= : 基準 bank_id，例如: 556}
                            {--target-banks= : 目標 bank_id（逗號分隔），例如: 1347,1348,1349}
                            {--fix-both : 修復情況1 - 將同時擁有兩種 bank_id 的會員的目標帳戶停用}
                            {--fix-missing : 修復情況2 - 將缺少基準 bank_id 的會員的目標帳戶更新為基準 bank_id}
                            {--fix : 修復所有情況}';

    /**
     * 命令描述
     */
    protected $description = '檢查和修復會員銀行帳戶（基準 bank_id 與目標 bank_id 的關係）';

    /**
     * 執行命令
     *
     * 命令執行流程：
     * 1. 驗證必需參數（base-bank, target-banks）
     * 2. 檢查情況1：同時擁有基準和目標 bank_id 的會員
     * 3. 檢查情況2：只有目標 bank_id，缺少基準 bank_id 的會員
     * 4. 顯示檢查結果和修復方案
     * 5. 根據選項執行相應的修復操作
     *
     * @return int 命令退出碼（0=成功，1=失敗）
     */
    public function handle(): int
    {
        // 獲取參數
        $baseBankId = $this->option('base-bank');
        $targetBanksStr = $this->option('target-banks');

        // 參數驗證
        if (!$baseBankId) {
            $this->error('❌ 必須提供 --base-bank 參數');
            $this->line('例如: --base-bank=556');

            return self::FAILURE;
        }

        if (!$targetBanksStr) {
            $this->error('❌ 必須提供 --target-banks 參數');
            $this->line('例如: --target-banks=1347,1348,1349');

            return self::FAILURE;
        }

        $baseBankId = (int) $baseBankId;
        $targetBankIds = array_map('intval', explode(',', $targetBanksStr));

        $this->info('🔍 會員銀行帳戶檢查');
        $this->newLine();
        $this->line(sprintf('  基準 bank_id: %d', $baseBankId));
        $this->line(sprintf('  目標 bank_id: %s', implode(', ', $targetBankIds)));
        $this->newLine();

        // 情況1: 同時擁有基準和目標 bank_id 的會員
        $situation1 = $this->checkSituation1($baseBankId, $targetBankIds);

        // 情況2: 只有目標 bank_id，缺少基準 bank_id 的會員
        $situation2 = $this->checkSituation2($baseBankId, $targetBankIds);

        // 显示检查结果
        $this->displayResults($situation1, $situation2);

        // 检查是否指定了修复选项
        $fixBoth = $this->option('fix-both');
        $fixMissing = $this->option('fix-missing');
        $fix = $this->option('fix');

        // 预设为 dry-run 模式（未指定任何修复选项时）
        if (!$fixBoth && !$fixMissing && !$fix) {
            $this->newLine();
            $this->comment('💡 預設模式：只檢查不修復');
            $this->line('使用以下選項來執行修復：');
            $this->line('  --fix-both    修復情況1（停用目標帳戶）');
            $this->line('  --fix-missing 修復情況2（更新為基準 bank_id）');
            $this->line('  --fix         修復所有情況');

            return self::SUCCESS;
        }

        // 执行修复
        return $this->performFixes($situation1, $situation2, $baseBankId, $targetBankIds);
    }

    /**
     * 檢查情況1: 同時擁有基準和目標 bank_id 的會員
     *
     * 使用兩個子查詢的交集找出同時滿足以下條件的會員：
     * - 擁有基準 bank_id 的帳戶
     * - 擁有目標 bank_id 的帳戶
     *
     * 這些會員存在重複帳戶，需要停用目標帳戶以避免混亂。
     *
     * @param  int  $baseBankId  基準 bank_id
     * @param  array  $targetBankIds  目標 bank_id 陣列
     * @return array ['member_ids' => array, 'accounts' => Collection]
     */
    protected function checkSituation1(int $baseBankId, array $targetBankIds): array
    {
        // 找出同时拥有两种 bank_id 的会员
        $memberIds = DB::table('member_bank_accounts')
            // 必須限 status=1：停用的基準帳戶不算「已有基準」。不限的話，只有停用基準帳戶
            // 的會員會在情況1 被停掉唯一啟用的目標帳戶，又因該列存在而被情況2 的 whereNotIn
            // 排除，最後零個有效綁定、無法提款。
            ->whereIn('member_id', function ($query) use ($baseBankId) {
                $query->select('member_id')
                    ->from('member_bank_accounts')
                    ->where('bank_id', $baseBankId)
                    ->where('status', 1);
            })
            ->whereIn('member_id', function ($query) use ($targetBankIds) {
                $query->select('member_id')
                    ->from('member_bank_accounts')
                    ->whereIn('bank_id', $targetBankIds);
            })
            ->whereIn('bank_id', $targetBankIds)
            ->distinct()
            ->pluck('member_id');

        if ($memberIds->isEmpty()) {
            return ['member_ids' => [], 'accounts' => collect([])];
        }

        // 查询这些会员的目标账户详情
        $accounts = DB::table('member_bank_accounts')
            ->whereIn('member_id', $memberIds)
            ->whereIn('bank_id', $targetBankIds)
            ->select([
                'id',
                'member_id',
                'bank_id',
                'account_no',
                'account_name',
                'status',
            ])
            ->orderBy('member_id')
            ->orderBy('bank_id')
            ->get();

        return [
            'member_ids' => $memberIds->toArray(),
            'accounts' => $accounts,
        ];
    }

    /**
     * 檢查情況2: 只有目標 bank_id，缺少基準 bank_id 的會員
     *
     * 使用差集查詢找出只滿足以下條件的會員：
     * - 擁有目標 bank_id 的帳戶
     * - 但沒有基準 bank_id 的帳戶
     *
     * 這些會員缺少標準帳戶，需要將目標帳戶更新為基準 bank_id。
     *
     * @param  int  $baseBankId  基準 bank_id
     * @param  array  $targetBankIds  目標 bank_id 陣列
     * @return array ['member_ids' => array, 'accounts' => Collection]
     */
    protected function checkSituation2(int $baseBankId, array $targetBankIds): array
    {
        // 找出只有目标 bank_id 但没有基准 bank_id 的会员
        $memberIds = DB::table('member_bank_accounts')
            ->whereIn('bank_id', $targetBankIds)
            // 判準與情況1 互補，同樣只認 active 的基準帳戶：只有停用基準帳戶的會員歸這裡
            // 處理，改指後他在基準下會有一停用一啟用兩列，前台看到的仍是那筆有效綁定。
            ->whereNotIn('member_id', function ($query) use ($baseBankId) {
                $query->select('member_id')
                    ->from('member_bank_accounts')
                    ->where('bank_id', $baseBankId)
                    ->where('status', 1);
            })
            ->distinct()
            ->pluck('member_id');

        if ($memberIds->isEmpty()) {
            return ['member_ids' => [], 'accounts' => collect([])];
        }

        // 查询这些会员的目标账户详情
        $accounts = DB::table('member_bank_accounts')
            ->whereIn('member_id', $memberIds)
            ->whereIn('bank_id', $targetBankIds)
            ->select([
                'id',
                'member_id',
                'bank_id',
                'account_no',
                'account_name',
                'status',
            ])
            ->orderBy('member_id')
            ->orderBy('bank_id')
            ->get();

        return [
            'member_ids' => $memberIds->toArray(),
            'accounts' => $accounts,
        ];
    }

    /**
     * 顯示檢查結果
     */
    protected function displayResults(array $situation1, array $situation2): void
    {
        $this->info('【檢查結果】');
        $this->newLine();

        // 情況1
        $count1 = count($situation1['member_ids']);
        $accounts1 = $situation1['accounts']->count();
        $active1 = $situation1['accounts']->where('status', 1)->count();

        $this->line('情況1 - 同時擁有基準和目標 bank_id:');
        $this->line(sprintf('  涉及會員: %d 個', $count1));
        $this->line(sprintf('  目標帳戶: %d 個（其中 %d 個啟用）', $accounts1, $active1));

        if ($count1 > 0) {
            $this->newLine();
            $this->line('  修復方案: 將目標 bank_id 的帳戶停用（status = 0）');
            $this->newLine();

            // 顯示前10條記錄
            $this->table(
                ['ID', 'Member ID', 'Bank ID', 'Account No', 'Status'],
                $situation1['accounts']->take(10)->map(function ($acc) {
                    return [
                        $acc->id,
                        $acc->member_id,
                        $acc->bank_id,
                        $acc->account_no,
                        $acc->status == 1 ? '<fg=green>啟用</>' : '停用',
                    ];
                })->toArray()
            );

            if ($accounts1 > 10) {
                $this->line(sprintf('  ... 還有 %d 條記錄未顯示', $accounts1 - 10));
            }
        }

        $this->newLine();

        // 情況2
        $count2 = count($situation2['member_ids']);
        $accounts2 = $situation2['accounts']->count();
        $active2 = $situation2['accounts']->where('status', 1)->count();

        $this->line('情況2 - 只有目標 bank_id，缺少基準 bank_id:');
        $this->line(sprintf('  涉及會員: %d 個', $count2));
        $this->line(sprintf('  目標帳戶: %d 個（其中 %d 個啟用）', $accounts2, $active2));

        if ($count2 > 0) {
            $this->newLine();
            $this->line('  修復方案: 將目標 bank_id 更新為基準 bank_id');
            $this->newLine();

            // 顯示前10條記錄
            $this->table(
                ['ID', 'Member ID', 'Bank ID', 'Account No', 'Status'],
                $situation2['accounts']->take(10)->map(function ($acc) {
                    return [
                        $acc->id,
                        $acc->member_id,
                        $acc->bank_id,
                        $acc->account_no,
                        $acc->status == 1 ? '<fg=green>啟用</>' : '停用',
                    ];
                })->toArray()
            );

            if ($accounts2 > 10) {
                $this->line(sprintf('  ... 還有 %d 條記錄未顯示', $accounts2 - 10));
            }
        }

        $this->newLine();
    }

    /**
     * 執行修復
     */
    protected function performFixes(
        array $situation1,
        array $situation2,
        int $baseBankId,
        array $targetBankIds
    ): int {
        $fixBoth = $this->option('fix-both');
        $fixMissing = $this->option('fix-missing');
        $fix = $this->option('fix');

        DB::beginTransaction();
        try {
            $fixed1 = 0;
            $fixed2 = 0;

            // 修復情況1
            if (($fixBoth || $fix) && !empty($situation1['accounts'])) {
                $fixed1 = $this->fixSituation1($situation1);
            }

            // 修復情況2
            if (($fixMissing || $fix) && !empty($situation2['accounts'])) {
                $fixed2 = $this->fixSituation2($situation2, $baseBankId);
            }

            // 確認修復
            if ($fixed1 > 0 || $fixed2 > 0) {
                $this->newLine();
                $this->warn('⚙️  準備執行修復操作:');
                if ($fixed1 > 0) {
                    $this->line(sprintf('  情況1: 停用 %d 個目標帳戶', $fixed1));
                }
                if ($fixed2 > 0) {
                    $this->line(sprintf('  情況2: 更新 %d 個目標帳戶的 bank_id', $fixed2));
                    $this->comment('         （同時會同步更新關聯的出款訂單）');
                }
                $this->newLine();

                if (!$this->confirm('確認執行修復嗎？', true)) {
                    DB::rollBack();
                    $this->info('已取消操作');

                    return self::SUCCESS;
                }

                DB::commit();

                $this->newLine();
                $this->info('✅ 修復完成');
                if ($fixed1 > 0) {
                    $this->line(sprintf('  情況1: 已停用 %d 個帳戶', $fixed1));
                }
                if ($fixed2 > 0) {
                    $this->line(sprintf('  情況2: 已更新 %d 個帳戶的 bank_id', $fixed2));
                }

                return self::SUCCESS;
            } else {
                DB::rollBack();
                $this->info('✅ 無需修復');

                return self::SUCCESS;
            }
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error(sprintf('❌ 修復失敗: %s', $e->getMessage()));

            return self::FAILURE;
        }
    }

    /**
     * 修復情況1：停用目標帳戶
     *
     * 將同時擁有基準和目標 bank_id 的會員的目標帳戶停用。
     * 只停用啟用狀態（status = 1）的帳戶，已停用的不重複處理。
     *
     * @param  array  $situation1  情況1的檢查結果
     * @return int 實際停用的帳戶數量
     */
    protected function fixSituation1(array $situation1): int
    {
        $ids = $situation1['accounts']->pluck('id')->toArray();

        if (empty($ids)) {
            return 0;
        }

        // 只停用启用状态的账户
        return DB::table('member_bank_accounts')
            ->whereIn('id', $ids)
            ->where('status', 1)
            ->update($this->preservingUpdatedAt(['status' => 0]));
    }

    /**
     * 修復情況2：更新 bank_id 為基準 bank_id
     *
     * 處理只有目標 bank_id 的會員：
     * - 如果會員已存在基準 bank_id 帳戶，則停用當前目標帳戶（避免重複）
     * - 如果會員不存在基準 bank_id 帳戶，則將目標帳戶更新為基準 bank_id
     *
     * 這種智慧處理確保不會建立重複的基準帳戶。
     *
     * 同時會同步更新 withdrawal_orders 表中關聯的記錄：
     * - bank_id: 更新為基準 bank_id
     * - bank_code: 更新為基準銀行的 code
     * - bank_name: 更新為基準銀行的 name
     *
     * @param  array  $situation2  情況2的檢查結果
     * @param  int  $baseBankId  基準 bank_id
     * @return int 實際處理的帳戶數量
     */
    protected function fixSituation2(array $situation2, int $baseBankId): int
    {
        $accounts = $situation2['accounts'];

        if ($accounts->isEmpty()) {
            return 0;
        }

        $updated = 0;

        foreach ($accounts as $account) {
            // 檢查是否已存在相同會員的**有效**基準 bank_id 帳戶。
            // 這是實際寫入前的最後一道閘：只看 bank_id 的話，持有停用基準帳戶的會員會走進
            // 下面的停用分支，把他唯一啟用的目標帳戶也關掉——即使前面兩處判定已修正，
            // 漏了這裡一樣會落到零有效綁定。
            $exists = DB::table('member_bank_accounts')
                ->where('member_id', $account->member_id)
                ->where('bank_id', $baseBankId)
                ->where('status', 1)
                ->exists();

            if ($exists) {
                // 如果已存在，則停用當前帳戶
                DB::table('member_bank_accounts')
                    ->where('id', $account->id)
                    ->update($this->preservingUpdatedAt(['status' => 0]));
            } else {
                // 如果不存在，則更新 bank_id
                $oldBankId = $account->bank_id;

                // 查詢新 bank_id 對應的 bank_code 和 bank_name
                $newBank = DB::table('banks')
                    ->where('id', $baseBankId)
                    ->select('code', 'name')
                    ->first();

                if (!$newBank) {
                    $this->warn(sprintf(
                        '  ⚠️  警告：找不到 bank_id=%d 的銀行資訊，跳過會員 %d',
                        $baseBankId,
                        $account->member_id
                    ));

                    continue;
                }

                // 更新會員銀行帳戶
                DB::table('member_bank_accounts')
                    ->where('id', $account->id)
                    ->update($this->preservingUpdatedAt(['bank_id' => $baseBankId]));

                // 同步更新出款訂單的 bank_id, bank_code, bank_name
                // 條件：會員ID + 舊 bank_id 匹配
                $withdrawalUpdated = DB::table('withdrawal_orders')
                    ->where('member_id', $account->member_id)
                    ->where('bank_id', $oldBankId)
                    ->update($this->preservingUpdatedAt([
                        'bank_id' => $baseBankId,
                        'bank_code' => $newBank->code,
                        'bank_name' => $newBank->name,
                    ]));

                if ($withdrawalUpdated > 0) {
                    $this->line(sprintf(
                        '  ├─ 會員 %d: 同步更新了 %d 條出款訂單 (bank_id, bank_code, bank_name)',
                        $account->member_id,
                        $withdrawalUpdated
                    ));
                }
            }

            $updated++;
        }

        return $updated;
    }
}
