<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * cash_flow_banks 資料完整性檢查命令
 *
 * 【分類】Maintenance — 可複用維運工具；不帶 `--fix*` 時為唯讀檢查。
 *
 * ## 發生原因
 * `cash_flow_banks`（渠道↔銀行對映）由 `cash-flow:sync` **以 name matching 重建**，
 * 而非以外鍵關聯維護。兩種失準因此會累積：
 * 1. **孤立記錄**——`bank_id` 指向已不存在（或已軟刪）的 banks 列
 * 2. **code 不同步**——`cfb.bank_code` 與所指 banks 列的 `code` 不一致
 *
 * ⚠️ 第 2 點要留意契約：`cfb.bank_code` 的語意是「**服務中 agent group 的 agent 層碼**」，
 * **不是 base(group 0) 的碼**（G2 階段二生產實證：把它正名成 base 碼會斷 g99 CIMB／g6 TnG 代付）。
 * 使用 `--fix-code` 前務必確認同步方向正確，否則會打斷代付。
 *
 * ## 修正目的
 * 掃出上述兩類失準並可選擇修復：`--fix-orphaned` 停用孤立記錄、`--fix-code` 同步代碼、
 * `--fix` 兩者都做。不帶參數時只出報表。
 *
 * ## 觸發時機
 * 1. **執行 `cash-flow:sync` 之後**（sync 是失準的主要來源）
 * 2. 銀行收斂／dedup／rename 之後
 * 3. 代付出現「不支援銀行」類問題時的排查起點
 * 4. 新金流商上線前的配置驗證
 *
 * ## 冪等性與重跑安全性
 * **冪等，重跑安全。** 不帶 `--fix*` 為純檢查；修復動作有 `confirm()` 與 `DB::transaction` 保護，
 * 已正確的列不會被重複處理。
 *
 * ## 退役條件
 * 當 `cash_flow_banks` 改以**外鍵約束 ＋ id-based 重建**（而非 name matching）維護後，
 * 這兩類失準不會再產生，本命令即可刪除。這與 `CashFlow/Guard/` 那批守護命令是同一個根因，
 * 根治後可一併退場。
 *
 * ## 關聯
 * - 失準來源：`app/Console/Commands/CashFlow/SyncCashFlowsCommand.php`
 * - 同根因的守護命令：`app/Console/Commands/CashFlow/Guard/`
 * - `cfb.bank_code` 契約：`app/Console/Commands/CashFlow/Guard/MergeBaseSynonymBanks.php`「範疇邊界」
 *
 * ## 命令用途
 * 檢查和修復 cash_flow_banks 表的資料完整性問題，確保金流商配置資料的正確性。
 *
 * ## 使用時機
 * 1. **日常健康檢查**：定期檢查金流商配置的資料完整性
 * 2. **資料維護**：在同步金流商配置後驗證資料正確性
 * 3. **故障排查**：金流商功能異常時，檢查配置資料是否有問題
 * 4. **上線前檢查**：部署新的金流商或更新配置前的驗證
 * 5. **資料遷移**：遷移或匯入金流商資料後的完整性驗證
 *
 * ## 檢查項目
 * ### 問題1：孤立記錄（bank_id 不存在）
 * - **定義**：cash_flow_banks.bank_id 在 banks 表中找不到對應記錄
 * - **影響**：無法取得銀行資訊，可能導致金流商功能異常
 * - **修復**：停用孤立記錄（status = 0），保留資料供追溯
 * - **SQL**：LEFT JOIN banks WHERE banks.id IS NULL
 *
 * ### 問題2：Code 不匹配（bank_code 不一致）
 * - **定義**：cash_flow_banks.bank_code 與 banks.code 不一致
 * - **影響**：資料不同步，可能顯示錯誤的銀行代碼
 * - **修復**：同步 bank_code 為 banks.code
 * - **SQL**：JOIN banks WHERE cfb.bank_code != banks.code
 *
 * ## 使用範例
 * ```bash
 * # 只檢查（預設行為）
 * php artisan cash-flow:check-banks-integrity
 *
 * # 修復所有問題
 * php artisan cash-flow:check-banks-integrity --fix
 *
 * # 只修復孤立記錄（停用）
 * php artisan cash-flow:check-banks-integrity --fix-orphaned
 *
 * # 只修復 code 不匹配（同步）
 * php artisan cash-flow:check-banks-integrity --fix-code
 * ```
 *
 * ## 安全機制
 * - 預設為只檢查模式，不會修改資料
 * - 所有修復操作使用交易保護（全部成功或全部回滾）
 * - 執行修復前需要使用者確認
 * - 孤立記錄只停用不刪除，保留歷史資料可追溯
 * - 只停用啟用狀態（status = 1）的孤立記錄
 *
 * ## 建議
 * - 新增到定期計劃任務（每天執行一次）
 * - 在同步金流商配置後執行檢查
 * - 發現問題及時修復，避免影響業務
 *
 * @author  Your Name
 *
 * @since   2026-02-03
 */
class CheckCashFlowBanksIntegrity extends Command
{
    /**
     * 命令签名
     */
    protected $signature = 'cash-flow:check-banks-integrity
                            {--fix : 自动修复发现的问题}
                            {--fix-orphaned : 只修复孤立记录（停用）}
                            {--fix-code : 只修复 code 不匹配（同步）}';

    /**
     * 命令描述
     */
    protected $description = '檢查 cash_flow_banks 的資料完整性（bank_id 不存在或 bank_code 不匹配）';

    /**
     * 執行命令
     *
     * 命令執行流程：
     * 1. 檢查孤立記錄（bank_id 在 banks 表中不存在）
     * 2. 檢查 code 不匹配（bank_code 與 banks.code 不一致）
     * 3. 顯示檢查結果和統計分析
     * 4. 根據選項執行相應的修復操作
     *
     * @return int 命令退出碼（0=成功，1=失敗）
     */
    public function handle(): int
    {
        $this->info('🔍 檢查 cash_flow_banks 資料完整性...');
        $this->newLine();

        // 問題1: bank_id 不存在（孤立記錄）
        $orphaned = $this->checkOrphanedRecords();

        // 問題2: bank_code 不匹配
        $mismatch = $this->checkCodeMismatch();

        // 彙總
        $totalIssues = $orphaned->count() + $mismatch->count();

        $this->newLine();
        $this->info('【檢查彙總】');
        $this->line(sprintf('  問題1 - bank_id 不存在: %d 條', $orphaned->count()));
        $this->line(sprintf('  問題2 - bank_code 不匹配: %d 條', $mismatch->count()));
        $this->line(sprintf('  總計問題記錄: %d 條', $totalIssues));
        $this->newLine();

        if ($totalIssues === 0) {
            $this->info('✅ 未發現問題，資料完整性良好！');

            return self::SUCCESS;
        }

        $this->error(sprintf('❌ 發現 %d 條問題記錄', $totalIssues));

        // 顯示詳細資訊
        $this->displayOrphanedRecords($orphaned);
        $this->displayMismatchRecords($mismatch);

        // 統計分析
        $this->displayStatistics($orphaned, $mismatch);

        // 處理修復選項
        if ($this->option('fix')) {
            return $this->fixAllIssues($orphaned, $mismatch);
        }

        if ($this->option('fix-orphaned')) {
            return $this->fixOrphaned($orphaned);
        }

        if ($this->option('fix-code')) {
            return $this->fixCodeMismatch($mismatch);
        }

        // 提示使用者
        $this->newLine();
        $this->comment('💡 提示：');
        $this->line('  使用 --fix 選項自動修復所有問題');
        $this->line('  使用 --fix-orphaned 選項只修復孤立記錄（停用）');
        $this->line('  使用 --fix-code 選項只修復 code 不匹配（同步）');

        return self::FAILURE;
    }

    /**
     * 检查孤立记录
     *
     * 使用 LEFT JOIN 查询找出 cash_flow_banks 中 bank_id 在 banks 表中不存在的记录。
     * 这些记录无法关联到有效的银行信息，可能是因为：
     * - 银行记录被删除但未清理关联
     * - 数据导入时 bank_id 填写错误
     * - 缺少外键约束导致数据不一致
     *
     * @return \Illuminate\Support\Collection 孤立记录集合
     */
    protected function checkOrphanedRecords()
    {
        return DB::table('cash_flow_banks as cfb')
            ->leftJoin('banks as b', 'cfb.bank_id', '=', 'b.id')
            ->leftJoin('cash_flows as cf', 'cfb.cash_flow_id', '=', 'cf.id')
            ->leftJoin('currencies as cur', 'cfb.currency_id', '=', 'cur.id')
            ->whereNull('b.id')
            ->select([
                'cfb.id',
                'cfb.cash_flow_id',
                'cf.name as cash_flow_name',
                'cfb.bank_id',
                'cfb.bank_code',
                'cfb.provider_bank_code',
                'cfb.currency_id',
                'cur.code as currency_code',
                'cfb.status',
            ])
            ->orderBy('cfb.status', 'desc')
            ->orderBy('cfb.cash_flow_id')
            ->get();
    }

    /**
     * 检查 Code 不匹配
     *
     * 使用 INNER JOIN 和 whereColumn 找出 bank_code 不一致的记录。
     * 这些记录的 cash_flow_banks.bank_code 与 banks.code 不同步，可能是因为：
     * - 更新 banks.code 时未同步 cash_flow_banks
     * - 手动修改了某一方的 code
     * - 程序逻辑错误导致使用了错误的 code
     *
     * @return \Illuminate\Support\Collection Code 不匹配的记录集合
     */
    protected function checkCodeMismatch()
    {
        return DB::table('cash_flow_banks as cfb')
            ->join('banks as b', 'cfb.bank_id', '=', 'b.id')
            ->leftJoin('cash_flows as cf', 'cfb.cash_flow_id', '=', 'cf.id')
            ->leftJoin('currencies as cur', 'cfb.currency_id', '=', 'cur.id')
            ->whereColumn('cfb.bank_code', '!=', 'b.code')
            ->select([
                'cfb.id',
                'cfb.cash_flow_id',
                'cf.name as cash_flow_name',
                'cfb.bank_id',
                'cfb.bank_code',
                'cfb.provider_bank_code',
                'cfb.currency_id',
                'cur.code as currency_code',
                'cfb.status',
                'b.code as banks_code',
                'b.name as bank_name',
            ])
            ->orderBy('cfb.status', 'desc')
            ->orderBy('cfb.cash_flow_id')
            ->get();
    }

    /**
     * 顯示孤立記錄
     */
    protected function displayOrphanedRecords($records): void
    {
        if ($records->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('【問題1: bank_id 不存在的記錄】');

        $active = $records->where('status', 1)->count();
        if ($active > 0) {
            $this->warn(sprintf('⚠️  其中 %d 條為啟用狀態', $active));
        }

        $this->table(
            ['ID', '金流商', 'Bank ID', 'Bank Code', 'Provider Code', '幣別', '狀態'],
            $records->map(function ($record) {
                return [
                    $record->id,
                    $record->cash_flow_name ?? 'N/A',
                    $record->bank_id,
                    $record->bank_code,
                    $record->provider_bank_code,
                    $record->currency_code ?? 'N/A',
                    $record->status == 1 ? '<fg=red>啟用</>' : '停用',
                ];
            })->toArray()
        );
    }

    /**
     * 顯示 Code 不匹配記錄
     */
    protected function displayMismatchRecords($records): void
    {
        if ($records->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('【問題2: bank_code 不匹配的記錄】');

        $active = $records->where('status', 1)->count();
        if ($active > 0) {
            $this->warn(sprintf('⚠️  其中 %d 條為啟用狀態', $active));
        }

        $rows = $records->take(20)->map(function ($record) {
            return [
                $record->id,
                $record->cash_flow_name ?? 'N/A',
                $record->bank_id,
                $record->bank_code,
                $record->banks_code,
                $record->bank_name ?? 'N/A',
                $record->currency_code ?? 'N/A',
                $record->status == 1 ? '<fg=red>啟用</>' : '停用',
            ];
        })->toArray();

        $this->table(
            ['ID', '金流商', 'Bank ID', 'CFB Code', 'Banks Code', 'Bank Name', '幣別', '狀態'],
            $rows
        );

        if ($records->count() > 20) {
            $this->line(sprintf('  ... 還有 %d 條記錄未顯示', $records->count() - 20));
        }
    }

    /**
     * 顯示統計分析
     */
    protected function displayStatistics($orphaned, $mismatch): void
    {
        $allIssues = $orphaned->merge($mismatch);

        if ($allIssues->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('【統計分析】');

        // 按金流商統計
        $this->line('按金流商分組：');
        $grouped = collect($allIssues)->groupBy('cash_flow_id');
        foreach ($grouped as $cashFlowId => $records) {
            $name = $records->first()->cash_flow_name ?? 'Unknown';
            $count = $records->count();
            $active = $records->where('status', 1)->count();
            $this->line(sprintf(
                '  金流商 ID %d (%s): %d 條%s',
                $cashFlowId,
                $name,
                $count,
                $active > 0 ? sprintf(' <fg=red>[含 %d 條啟用]</>', $active) : ''
            ));
        }

        // 按狀態統計
        $this->newLine();
        $this->line('按狀態分組：');
        $byStatus = collect($allIssues)->groupBy('status');
        foreach ($byStatus as $status => $records) {
            $statusText = $status == 1 ? '啟用' : '停用';
            $this->line(sprintf('  %s: %d 條', $statusText, $records->count()));
        }
    }

    /**
     * 修復所有問題
     *
     * 同時執行兩種修復操作：
     * 1. 停用孤立記錄（只停用 status = 1 的記錄）
     * 2. 同步 bank_code（所有不匹配的記錄都會同步）
     *
     * 所有操作在交易中執行，確保原子性。
     *
     * @param  \Illuminate\Support\Collection  $orphaned  孤立記錄集合
     * @param  \Illuminate\Support\Collection  $mismatch  Code 不匹配記錄集合
     * @return int 命令退出碼
     */
    protected function fixAllIssues($orphaned, $mismatch): int
    {
        $this->newLine();
        $this->warn('⚙️  準備修復所有發現的問題...');

        if (!$this->confirm('確認要修復這些問題嗎？', true)) {
            $this->info('已取消操作');

            return self::SUCCESS;
        }

        DB::beginTransaction();
        try {
            $orphanedFixed = 0;
            $codeFixed = 0;

            // 修復孤立記錄
            if ($orphaned->isNotEmpty()) {
                $ids = $orphaned->pluck('id');
                $orphanedFixed = DB::table('cash_flow_banks')
                    ->whereIn('id', $ids)
                    ->where('status', 1)
                    ->update(['status' => 0]);
            }

            // 修復 code 不匹配
            if ($mismatch->isNotEmpty()) {
                foreach ($mismatch as $record) {
                    DB::table('cash_flow_banks')
                        ->where('id', $record->id)
                        ->update(['bank_code' => $record->banks_code]);
                    $codeFixed++;
                }
            }

            DB::commit();

            $this->newLine();
            $this->info('✅ 修復完成');
            $this->line(sprintf('  停用孤立記錄: %d 條', $orphanedFixed));
            $this->line(sprintf('  同步 bank_code: %d 條', $codeFixed));

            return self::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error(sprintf('❌ 修復失敗: %s', $e->getMessage()));

            return self::FAILURE;
        }
    }

    /**
     * 只修復孤立記錄
     *
     * 將 bank_id 不存在的記錄停用（status = 0）。
     * 只停用啟用狀態的記錄，已停用的不重複處理。
     * 不刪除記錄，保留歷史資料供追溯。
     *
     * @param  \Illuminate\Support\Collection  $orphaned  孤立記錄集合
     * @return int 命令退出碼
     */
    protected function fixOrphaned($orphaned): int
    {
        if ($orphaned->isEmpty()) {
            $this->info('✅ 無孤立記錄需要修復');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('⚙️  準備停用孤立記錄...');

        if (!$this->confirm('確認要停用這些孤立記錄嗎？', true)) {
            $this->info('已取消操作');

            return self::SUCCESS;
        }

        DB::beginTransaction();
        try {
            $ids = $orphaned->pluck('id');
            $updated = DB::table('cash_flow_banks')
                ->whereIn('id', $ids)
                ->where('status', 1)
                ->update(['status' => 0]);

            DB::commit();

            $this->info(sprintf('✅ 已停用 %d 條孤立記錄', $updated));

            return self::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error(sprintf('❌ 修復失敗: %s', $e->getMessage()));

            return self::FAILURE;
        }
    }

    /**
     * 只修復 code 不匹配
     *
     * 將 cash_flow_banks.bank_code 同步為 banks.code。
     * 所有不匹配的記錄都會被修復，無論啟用或停用狀態。
     * 逐條更新以確保資料準確性。
     *
     * @param  \Illuminate\Support\Collection  $mismatch  Code 不匹配記錄集合
     * @return int 命令退出碼
     */
    protected function fixCodeMismatch($mismatch): int
    {
        if ($mismatch->isEmpty()) {
            $this->info('✅ 無 code 不匹配需要修復');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('⚙️  準備同步 bank_code...');

        if (!$this->confirm('確認要同步這些 bank_code 嗎？', true)) {
            $this->info('已取消操作');

            return self::SUCCESS;
        }

        DB::beginTransaction();
        try {
            $updated = 0;
            foreach ($mismatch as $record) {
                DB::table('cash_flow_banks')
                    ->where('id', $record->id)
                    ->update(['bank_code' => $record->banks_code]);
                $updated++;
            }

            DB::commit();

            $this->info(sprintf('✅ 已同步 %d 條記錄的 bank_code', $updated));

            return self::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error(sprintf('❌ 修復失敗: %s', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
