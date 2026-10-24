<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use App\Enums\CashFlow\OrphanReasonEnum;
use App\Helpers\Common;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Services\CashFlow\OrphanDetectorService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 對 check:cashflow-orphans 偵測到的孤立 acfot 執行軟下架（status=0）。
 *
 * 【分類】Maintenance — 可複用維運工具，跟在 `check:cashflow-orphans` 之後跑。
 *
 * ## 發生原因
 * 與 `check:cashflow-orphans` 同源：四層鏈（`cash_flows` → `order_types` →
 * `agent_cash_flows` → `acfot`）**上游停用不會連動下游**，留下 status=1 的孤立 acfot，
 * 導致前端顯示得出、實際下不了單的支付方式。
 *
 * ## 修正目的
 * 把診斷出的孤立 acfot **軟下架**（`status=0`）。刻意不硬刪——保留列以便查歷史訂單
 * 與必要時還原（改回 status=1 即可）。
 *
 * ## 觸發時機
 * `check:cashflow-orphans` 報出孤兒之後。兩支是成對使用：**先診斷、再修復**。
 *
 * ## 冪等性與重跑安全性
 * **冪等，重跑安全。** 已是 status=0 的列不會再被處理。保護層：
 * `--dry-run` 預覽、下架前列出清單並 `confirm()`、環境確認、寫入包 `DB::transaction`；
 * `--force` 供 CI／`--no-interaction` 場景略過互動。
 * 可用 `--reason` 只下架特定成因，避免一次動到不想動的類別。
 *
 * ## 退役條件
 * 同 `check:cashflow-orphans`——四層之間有 FK cascade 或連動停用 Observer 之後，
 * 兩支一併刪除。
 *
 * ## 關聯
 * - 配對的診斷命令：`check:cashflow-orphans`（**必須先跑**）
 * - 偵測邏輯：`OrphanDetectorService`、`OrphanReasonEnum`
 *
 * 預設行為：列出所有將被下架的 acfot 並要求使用者確認。
 * 加 --dry-run 只預覽不動資料；加 --force 略過確認（CI 用）。
 *
 * 用法：
 *   php artisan fix:cashflow-orphans --cashflow=101 --dry-run
 *   php artisan fix:cashflow-orphans --cashflow=PayPro --reason=L2_order_type_disabled
 *   php artisan fix:cashflow-orphans --cashflow=101 --force --no-interaction
 */
class FixCashFlowOrphans extends Command
{
    protected $signature = 'fix:cashflow-orphans
                            {--cashflow= : cash_flow ID / code / name（必填）}
                            {--reason= : 只下架指定 orphan_reason（多筆以逗號分隔）}
                            {--dry-run : 乾跑模式，不寫入資料庫}
                            {--force : 略過環境與下架確認}';

    protected $description = '對 acfot 孤立資料執行軟下架（status=0），先預覽再確認';

    public function handle(OrphanDetectorService $detector): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');

        if ($isDryRun) {
            $this->warn('⚠️  乾跑模式：執行後不會寫入資料庫');
            $this->newLine();
        }

        if (!$isDryRun && !$isForce && !$this->confirmExecutionEnvironment()) {
            return self::FAILURE;
        }

        $input = $this->option('cashflow');
        if (!$input) {
            $this->error('❌ 必須指定 --cashflow（ID / code / name）');

            return self::FAILURE;
        }

        $cashFlow = $this->resolveCashFlow((string) $input);
        if (!$cashFlow) {
            return self::FAILURE;
        }

        $reasonFilter = $this->parseReasonFilter();
        if ($reasonFilter === false) {
            return self::FAILURE;
        }

        $orphans = $detector->findOrphans($cashFlow->id);

        if ($reasonFilter !== null) {
            $orphans = $orphans->filter(
                fn (object $row): bool => in_array($row->reason, $reasonFilter, true)
            )->values();
        }

        if ($orphans->isEmpty()) {
            $this->info('✅ 未發現可下架的孤立 acfot');

            return self::SUCCESS;
        }

        $this->renderPreview($orphans, $isDryRun);

        if ($isDryRun) {
            $this->newLine();
            $this->comment('正式執行請移除 --dry-run');

            return self::SUCCESS;
        }

        if (!$isForce && !$this->confirm(sprintf('確定將 %d 筆 acfot 設為 status=0？', $orphans->count()), false)) {
            $this->warn('已取消');

            return self::FAILURE;
        }

        return $this->executeDisable($cashFlow, $orphans);
    }

    private function confirmExecutionEnvironment(): bool
    {
        $dbConnection = config('database.default');
        if (in_array(strtolower((string) $dbConnection), ['mysql', 'dev', 'testing'], true)) {
            return true;
        }

        $this->warn(sprintf('⚠️  當前資料庫連線: %s', $dbConnection));

        return $this->confirm('確定要在此環境執行？', false);
    }

    private function resolveCashFlow(string $input): ?CashFlow
    {
        if (ctype_digit($input)) {
            $cashFlow = CashFlow::find((int) $input);
            if ($cashFlow) {
                return $cashFlow;
            }
        }

        $matches = CashFlow::query()
            ->where('third_party_code', $input)
            ->orWhere('name', $input)
            ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->isEmpty()) {
            $this->error(sprintf('❌ 找不到對應的金流商: %s', $input));

            return null;
        }

        $this->error(sprintf('❌ 找到多筆金流商符合 "%s"，請改用 ID 精確指定', $input));

        return null;
    }

    /**
     * @return array<int, OrphanReasonEnum>|false|null
     */
    private function parseReasonFilter(): array|false|null
    {
        $input = $this->option('reason');
        if (!$input) {
            return null;
        }

        $items = array_filter(array_map('trim', explode(',', (string) $input)));
        $reasons = [];

        foreach ($items as $item) {
            $matched = OrphanReasonEnum::tryFrom(strtolower($item))
                ?? OrphanReasonEnum::tryFrom($item);

            if ($matched === null) {
                $this->error(sprintf(
                    '❌ 未知的 orphan_reason: %s（可用值：%s）',
                    $item,
                    implode(', ', OrphanReasonEnum::values()),
                ));

                return false;
            }

            $reasons[] = $matched;
        }

        return $reasons;
    }

    /**
     * @param  Collection<int, \stdClass>  $orphans
     */
    private function renderPreview(Collection $orphans, bool $isDryRun): void
    {
        $this->info($isDryRun ? '🔍 預覽（DRY-RUN）：' : '🔍 預覽：將對以下 acfot 執行 status=0');
        $this->table(
            ['acfot_id', 'acf_id', 'order_type_id', 'agent_group', 'currency_id', 'reason'],
            $orphans->map(fn (object $r) => [
                $r->acfot_id,
                $r->agent_cash_flow_id,
                $r->order_type_id ?? '<null>',
                $r->agent_group_id,
                $r->currency_id,
                $r->reason->label(),
            ])->all()
        );

        $this->newLine();
        $byReason = $orphans->groupBy(fn (object $r) => $r->reason->value);
        $this->line(sprintf('影響：%d 筆 acfot', $orphans->count()));
        foreach ($byReason as $reasonValue => $rows) {
            $reason = OrphanReasonEnum::from($reasonValue);
            $this->line(sprintf('  %s → %d 筆（filter: %s）', $reason->label(), $rows->count(), $reason->value));
        }
    }

    /**
     * @param  Collection<int, \stdClass>  $orphans
     */
    private function executeDisable(CashFlow $cashFlow, Collection $orphans): int
    {
        $acfotIds = $orphans->pluck('acfot_id')->map(fn (mixed $id): int => (int) $id)->all();
        $reasonCounts = $orphans->groupBy(fn (object $r) => $r->reason->value)
            ->map(fn (Collection $g) => $g->count())
            ->all();

        try {
            $affected = DB::transaction(function () use ($acfotIds): int {
                return AgentCashFlowOrderType::query()
                    ->whereIn('id', $acfotIds)
                    ->update(['status' => 0]);
            });

            Log::info('cashflow-orphan: acfot disabled', [
                'cash_flow_id' => $cashFlow->id,
                'cash_flow_name' => $cashFlow->name,
                'affected_count' => $affected,
                'acfot_ids' => $acfotIds,
                'reason_counts' => $reasonCounts,
                'executed_by' => 'artisan:fix:cashflow-orphans',
            ]);

            $this->newLine();
            $this->info(sprintf('✅ 已下架 %d 筆 acfot', $affected));

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('cashflow-orphan: disable failed', [
                'cash_flow_id' => $cashFlow->id,
                'acfot_ids' => $acfotIds,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            $this->error(sprintf('❌ 執行失敗: %s', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
