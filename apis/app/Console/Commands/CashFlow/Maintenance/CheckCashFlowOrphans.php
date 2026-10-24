<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use App\Enums\CashFlow\CashFlowScopeEnum;
use App\Enums\CashFlow\OrphanReasonEnum;
use App\Facades\BotNotify;
use App\Models\CashFlow;
use App\Services\CashFlow\OrphanDetectorService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * 校驗 agent_cash_flow_order_types 的孤立資料（唯讀）。
 *
 * 【分類】Maintenance — 可複用**唯讀**診斷工具，隨時可跑。
 *
 * ## 發生原因
 * ACFOT 是四層鏈的末端：`cash_flows` → `order_types` → `agent_cash_flows` → `acfot`。
 * 上游任一層被停用或移除時，**下游 acfot 不會連動**（無 FK cascade、無 Observer 連動停用），
 * 於是留下「自己 status=1、但上游鏈已斷」的孤立列。這些列會讓渠道查詢撈出實際不可用的
 * 支付方式，前端顯示得出來、下單卻失敗。
 *
 * ## 修正目的
 * 唯讀盤點：由外向內走完四層，列出所有 status=1 但上游鏈斷裂的 acfot，
 * 並依成因（`OrphanReasonEnum`）分類，供 `fix:cashflow-orphans` 決定要下架哪些。
 *
 * ## 觸發時機
 * 1. 停用某個 cash_flow／order_type／agent_cash_flow **之後**（確認下游沒留孤兒）
 * 2. 前端出現「顯示得到但下不了單」的支付方式時
 * 3. 例行配置健檢；`--json` 可供自動化取用
 *
 * ## 冪等性與重跑安全性
 * **唯讀，絕對安全。** 不寫入任何資料，隨時可跑、可重複跑。
 *
 * ## 退役條件
 * 當四層之間建立 FK cascade，或加上「上游停用即連動停用下游」的 Observer 之後，
 * 孤兒不會再產生，本命令與 `fix:cashflow-orphans` 可一併刪除。
 *
 * ## 關聯
 * - 配對的修復命令：`fix:cashflow-orphans`（本命令唯讀、那支才寫入）
 * - 偵測邏輯：`OrphanDetectorService`、`OrphanReasonEnum`
 * - APIs 範疇邊界（cf > 62）：`CashFlowScopeEnum::LEGACY_BOUNDARY_CASH_FLOW_ID`
 *
 * 4-Layer 由外向內檢查 cash_flows → order_types → agent_cash_flows → agent_cash_flow_order_types，
 * 列出所有 status=1 但上游鏈斷裂的 acfot，依成因（OrphanReasonEnum）分類報告。
 *
 * 預設只掃 APIs 範疇（cf>62）。要看舊系統殘留加 --scope=legacy；要全部加 --scope=all。
 *
 * 用法：
 *   php artisan check:cashflow-orphans                       # 預設：只看 APIs (cf>62)
 *   php artisan check:cashflow-orphans --scope=legacy        # 看舊系統殘留 (cf<=62)
 *   php artisan check:cashflow-orphans --scope=all           # 含舊系統，掃全部
 *   php artisan check:cashflow-orphans --cashflow=101        # 限定 cash_flow ID（忽略 scope）
 *   php artisan check:cashflow-orphans --reason=L2_order_type_disabled
 *   php artisan check:cashflow-orphans --json                # JSON 輸出
 *
 * 後續執行下架請改跑 fix:cashflow-orphans。
 */
class CheckCashFlowOrphans extends Command
{
    protected $signature = 'check:cashflow-orphans
                            {--cashflow= : cash_flow ID / code / name；指定後忽略 --scope}
                            {--scope=apis : 範疇 apis|legacy|all；預設 apis (cf>62)}
                            {--reason= : 只列指定 orphan_reason（多筆以逗號分隔）}
                            {--json : 以 JSON 格式輸出}
                            {--notify : 排程安全網模式：發現孤兒即 BotNotify，且一律回 SUCCESS（唯讀，不動資料）}';

    protected $description = '校驗 agent_cash_flow_order_types 的孤立資料（4-layer 健康度檢查，唯讀）';

    public function handle(OrphanDetectorService $detector): int
    {
        $cashFlow = null;
        if ($input = $this->option('cashflow')) {
            $cashFlow = $this->resolveCashFlow((string) $input);
            if (!$cashFlow) {
                return self::FAILURE;
            }
        }

        $scope = $this->parseScope();
        if ($scope === null) {
            return self::FAILURE;
        }

        $reasonFilter = $this->parseReasonFilter();

        $orphans = $detector->findOrphans($cashFlow?->id, $scope);

        if ($reasonFilter !== null) {
            $orphans = $orphans->filter(fn (object $row): bool => in_array($row->reason, $reasonFilter, true))
                ->values();
        }

        if ($this->option('json')) {
            $this->line($this->renderJson($orphans, $cashFlow, $scope));

            return self::SUCCESS;
        }

        $this->renderHumanReport($detector, $cashFlow, $scope, $orphans);

        // 排程安全網模式：孤兒存在不視為命令失敗（否則排程每天紅、告警與 BotNotify 重複），
        // 改以 BotNotify 傳達。Observer 只覆蓋 Eloquent 路徑，raw SQL 直改 DB 靠這裡接住。
        if ($this->option('notify')) {
            $this->notifyOrphans($orphans, $cashFlow, $scope);

            return self::SUCCESS;
        }

        return $orphans->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 發現孤兒時發 BotNotify；無孤兒保持靜默（避免每日噪音）
     *
     * @param  Collection<int, \stdClass>  $orphans
     */
    private function notifyOrphans(Collection $orphans, ?CashFlow $cashFlow, CashFlowScopeEnum $scope): void
    {
        if ($orphans->isEmpty()) {
            return;
        }

        $byReason = $orphans
            ->groupBy(fn (object $row): string => $row->reason->value)
            ->map(fn (Collection $rows): int => $rows->count());

        $lines = $byReason
            ->map(fn (int $count, string $reason): string => sprintf(
                '  • %s：%d 筆',
                OrphanReasonEnum::from($reason)->label(),
                $count,
            ))
            ->values()
            ->all();

        $message = sprintf(
            "⚠️ 偵測到孤立 acfot 共 %d 筆（範疇：%s）\n%s\n\n下架請跑：php artisan fix:cashflow-orphans --cashflow=<id>\n連動下架的還原請跑：php artisan fix:acf-cascade-restore",
            $orphans->count(),
            $cashFlow !== null ? sprintf('cash_flow %d（%s）', $cashFlow->id, $cashFlow->name) : $scope->value,
            implode("\n", $lines),
        );

        BotNotify::send($message, [
            'connection' => (string) config('database.default'),
            'total' => $orphans->count(),
            'by_reason' => $byReason->all(),
            'cash_flow_id' => $cashFlow?->id,
            'scope' => $scope->value,
        ]);
    }

    private function parseScope(): ?CashFlowScopeEnum
    {
        $input = (string) $this->option('scope');
        $scope = CashFlowScopeEnum::tryFrom(strtolower($input));

        if ($scope === null) {
            $this->error(sprintf(
                '❌ 未知的 scope: %s（可用值：%s）',
                $input,
                implode(', ', CashFlowScopeEnum::values()),
            ));

            return null;
        }

        return $scope;
    }

    /**
     * 解析 --cashflow 參數：先試 ID，再試 third_party_code，最後試 name 模糊比對。
     */
    private function resolveCashFlow(string $input): ?CashFlow
    {
        $query = CashFlow::query();

        if (ctype_digit($input)) {
            $cashFlow = $query->find((int) $input);
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
            $matches = CashFlow::query()
                ->where('name', 'like', '%' . $input . '%')
                ->orWhere('third_party_code', 'like', '%' . $input . '%')
                ->get();
        }

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->isEmpty()) {
            $this->error(sprintf('❌ 找不到對應的金流商: %s', $input));

            return null;
        }

        $this->warn(sprintf('找到多筆金流商符合 "%s"：', $input));
        $this->table(
            ['id', 'name', 'third_party_code', 'type', 'status'],
            $matches->map(fn (CashFlow $cf) => [
                $cf->id, $cf->name, $cf->third_party_code, $cf->type, $cf->status,
            ])->all()
        );
        $this->error('請改用 ID 精確指定');

        return null;
    }

    /**
     * @return array<int, OrphanReasonEnum>|null
     */
    private function parseReasonFilter(): ?array
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

                continue;
            }

            $reasons[] = $matched;
        }

        return $reasons;
    }

    /**
     * @param  Collection<int, \stdClass>  $orphans
     */
    private function renderHumanReport(OrphanDetectorService $detector, ?CashFlow $cashFlow, CashFlowScopeEnum $scope, Collection $orphans): void
    {
        if ($cashFlow !== null) {
            $this->printLayerContext($detector, $cashFlow);
        } else {
            $this->info(sprintf('🔭 範疇：%s', $scope->description()));
        }

        $this->newLine();
        $this->info('═══ Layer 4: agent_cash_flow_order_types 孤立 ═══');

        if ($orphans->isEmpty()) {
            $this->info('✅ 未發現孤立 acfot');
            if ($cashFlow === null && $scope === CashFlowScopeEnum::APIS) {
                $this->comment('  （想看舊系統殘留請加 --scope=legacy）');
            }

            return;
        }

        $showCashFlowColumns = $cashFlow === null;

        $headers = $showCashFlowColumns
            ? ['acfot_id', 'cf_id', 'cf_name', 'acf_id', 'order_type_id', 'agent_group', 'currency_id', 'reason']
            : ['acfot_id', 'acf_id', 'order_type_id', 'agent_group', 'currency_id', 'reason'];

        $this->table(
            $headers,
            $orphans->map(function (object $r) use ($showCashFlowColumns): array {
                $base = [
                    $r->acfot_id,
                    $r->agent_cash_flow_id,
                    $r->order_type_id ?? '<null>',
                    $r->agent_group_id,
                    $r->currency_id,
                    $r->reason->label(),
                ];

                if (!$showCashFlowColumns) {
                    return $base;
                }

                return [
                    $r->acfot_id,
                    $r->acf_cash_flow_id,
                    $r->cash_flow_name ?? '<missing>',
                    $r->agent_cash_flow_id,
                    $r->order_type_id ?? '<null>',
                    $r->agent_group_id,
                    $r->currency_id,
                    $r->reason->label(),
                ];
            })->all()
        );

        $this->newLine();
        $this->info('═══ 摘要 ═══');
        $this->line(sprintf('總孤立 acfot：%d 筆', $orphans->count()));

        $byReason = $orphans->groupBy(fn (object $r) => $r->reason->value);
        $this->line('分類：');
        foreach ($byReason as $reasonValue => $rows) {
            $reason = OrphanReasonEnum::from($reasonValue);
            $this->line(sprintf('  %s → %d 筆', $reason->label(), $rows->count()));
            $this->line(sprintf('    說明：%s', $reason->description()));
            $this->line(sprintf('    filter: %s', $reason->value));
        }

        if ($showCashFlowColumns) {
            $this->newLine();
            $this->line('受影響 cash_flow：');
            $byCashFlow = $orphans->groupBy(fn (object $r) => (int) $r->acf_cash_flow_id);
            foreach ($byCashFlow as $cashFlowId => $rows) {
                $name = $rows->first()->cash_flow_name ?? '<missing>';
                $this->line(sprintf('  [%d] %s → %d 筆', $cashFlowId, $name, $rows->count()));
            }
        }

        $this->newLine();
        $this->comment('下一步：');
        if ($cashFlow !== null) {
            $this->line(sprintf('  php artisan fix:cashflow-orphans --cashflow=%d --dry-run', $cashFlow->id));

            return;
        }

        $byCashFlow ??= $orphans->groupBy(fn (object $r) => (int) $r->acf_cash_flow_id);
        foreach ($byCashFlow as $cashFlowId => $_rows) {
            $this->line(sprintf('  php artisan fix:cashflow-orphans --cashflow=%d --dry-run', $cashFlowId));
        }
    }

    private function printLayerContext(OrphanDetectorService $detector, CashFlow $cashFlow): void
    {
        $this->info('═══ Layer 1: cash_flows 健康度 ═══');
        $this->table(
            ['id', 'name', 'type', 'third_party_code', 'status'],
            [[$cashFlow->id, $cashFlow->name, $cashFlow->type, $cashFlow->third_party_code, $cashFlow->status]]
        );
        if ((int) $cashFlow->status === 0) {
            $this->warn('⚠️  金流商整體已停用，下游 acfot 全部視為孤立');
        }

        $this->newLine();
        $this->info('═══ Layer 2: order_types 異常 ═══');
        $unhealthyOts = $detector->findUnhealthyOrderTypes($cashFlow->id);
        if ($unhealthyOts->isEmpty()) {
            $this->info('（全部健康）');
        } else {
            $this->table(
                ['id', 'name', 'parent', 'status', 'parent_status', 'cf_status'],
                $unhealthyOts->map(fn (object $r) => [
                    $r->id, $r->name, $r->parent, $r->ot_status,
                    $r->parent_status ?? '<null>',
                    $r->cf_status ?? '<null>',
                ])->all()
            );
        }

        $this->newLine();
        $this->info('═══ Layer 3: agent_cash_flows 異常 ═══');
        $unhealthyAcfs = $detector->findUnhealthyAgentCashFlows($cashFlow->id);
        if ($unhealthyAcfs->isEmpty()) {
            $this->info('（全部健康）');
        } else {
            $this->table(
                ['id', 'agent_group_id', 'currency_id', 'name', 'acf_status', 'cf_status'],
                $unhealthyAcfs->map(fn (object $r) => [
                    $r->id, $r->agent_group_id, $r->currency_id, $r->name,
                    $r->acf_status, $r->cf_status ?? '<null>',
                ])->all()
            );
        }
    }

    /**
     * @param  Collection<int, \stdClass>  $orphans
     */
    private function renderJson(Collection $orphans, ?CashFlow $cashFlow, CashFlowScopeEnum $scope): string
    {
        $payload = [
            'scope' => $scope->value,
            'cash_flow_id' => $cashFlow?->id,
            'cash_flow_name' => $cashFlow?->name,
            'total' => $orphans->count(),
            'by_reason' => $orphans->groupBy(fn (object $r) => $r->reason->value)
                ->map(fn (Collection $g) => $g->count()),
            'orphans' => $orphans->map(fn (object $r): array => [
                'acfot_id' => $r->acfot_id,
                'cash_flow_id' => (int) $r->acf_cash_flow_id,
                'cash_flow_name' => $r->cash_flow_name,
                'agent_cash_flow_id' => $r->agent_cash_flow_id,
                'order_type_id' => $r->order_type_id,
                'agent_group_id' => $r->agent_group_id,
                'currency_id' => $r->currency_id,
                'reason' => $r->reason->value,
            ])->values(),
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
