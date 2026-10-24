<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use App\Enums\CashFlowTypeEnum;
use App\Helpers\Common;
use App\Models\AcfCascadeDeactivateLog;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 軟刪除單筆 ACF（連動軟刪旗下 ACFOT，可還原），或依審計 log 還原（--restore）。
 *
 * 【分類】Maintenance — 可複用維運工具，與 {@see \App\Observers\AgentCashFlowObserver} 成對。
 *
 * ## 發生原因
 * AddAgentCashFlow 歷史缺陷（name 識別漂移）曾在同一 channel key 下建立重複的 ACF；
 * 重複列會被拆分模式的防呆擋下（「channel key 對應多筆 AgentCashFlow」），
 * 必須先清掉多餘列才能續跑。停用（status=0）不能解封——防呆看的是列存在與否。
 *
 * ## 修正目的
 * 提供正式的刪除入口：SoftDeletes 軟刪（列保留、可回滾），連動與審計由
 * AgentCashFlowObserver 統一承擔（連動軟刪 ACFOT、ACF status 歸 0 作舊系統保險、
 * 逐筆寫 soft_delete 審計）。**只允許刪無業務足跡的列**：五張事實表
 * （orders / order_histories / withdrawal_payout_records / ota_orders /
 * summary_profit_share_payment_costs）任一有引用即拒刪——有史料的是真配置。
 *
 * ## 冪等性與重跑安全性
 * **冪等，重跑安全。** 已軟刪的列重跑刪除＝no-op；還原依審計 log 閉環
 * （restored_by_log_id），重複還原不再命中。保護層：--dry-run 預覽、
 * 引用預檢、confirm()、環境確認、寫入包 DB::transaction。
 *
 * ## 關聯
 * - 連動／審計實作：{@see \App\Observers\AgentCashFlowObserver}
 * - 下架連動的還原（status 軌）：fix:acf-cascade-restore
 * - 還原後 ACF status 維持 0，確認無誤後由人工顯式啟用
 *
 * 用法：
 *   php artisan fix:acf-delete --acf=433 --dry-run
 *   php artisan fix:acf-delete --acf=433
 *   php artisan fix:acf-delete --acf=433 --restore
 *   php artisan fix:acf-delete --orphans --dry-run   # 批次掃描空殼 ACF（零 ACFOT）
 *   php artisan fix:acf-delete --orphans             # 批次軟刪無業務足跡的空殼 ACF
 */
class AcfDelete extends Command
{
    protected $signature = 'fix:acf-delete
                            {--acf= : 目標 agent_cash_flows.id（單筆模式必填）}
                            {--orphans : 批次模式：掃描空殼代收 ACF（type=1 且零 ACFOT），軟刪其中無業務足跡者；代付／自助類（type 2/3/4）一律排除}
                            {--restore : 還原模式：復活軟刪的 ACF 與其連動軟刪的 ACFOT}
                            {--dry-run : 乾跑模式，不寫入資料庫}
                            {--force : 略過環境與執行確認}';

    protected $description = '軟刪除 ACF（連動軟刪旗下 ACFOT，可還原）：--acf 單筆或 --orphans 批次掃空殼；限無業務足跡的列';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');
        $isOrphans = (bool) $this->option('orphans');
        $acfId = (int) $this->option('acf');

        if ($isOrphans && ($this->option('acf') !== null || $this->option('restore'))) {
            $this->error('❌ --orphans 為批次模式，不可與 --acf／--restore 併用（還原請逐筆 --acf=<id> --restore）');

            return self::FAILURE;
        }

        if (!$isOrphans && $acfId <= 0) {
            $this->error('❌ 請以 --acf=<id> 指定目標 agent_cash_flows.id（批次掃空殼改用 --orphans）');

            return self::FAILURE;
        }

        if ($isDryRun) {
            $this->warn('⚠️  乾跑模式：執行後不會寫入資料庫');
            $this->newLine();
        }

        if (!$isDryRun && !$isForce && !$this->confirmExecutionEnvironment()) {
            return self::FAILURE;
        }

        if ($isOrphans) {
            return $this->handleOrphans($isDryRun, $isForce);
        }

        $acf = AgentCashFlow::withTrashed()->find($acfId);

        if (!$acf) {
            $this->error(sprintf('❌ 找不到 AgentCashFlow %d', $acfId));

            return self::FAILURE;
        }

        return $this->option('restore')
            ? $this->handleRestore($acf, $isDryRun, $isForce)
            : $this->handleDelete($acf, $isDryRun, $isForce);
    }

    /**
     * 批次模式：掃描空殼 ACF（存活但零 ACFOT，含軟刪 ACFOT 也算零）並軟刪無業務足跡者
     *
     * 掃描限定一般代收（cash_flows.type = DEPOSIT）：「零 ACFOT＝孤兒」只對代收成立
     * （代收上架必經 OT→ACFOT→ACF→CF 四層 status 鏈）。其餘型別一律排除——
     * 代付（3）／自助洗分（4）依設計不掛 ACFOT、零 ACFOT 是常態，且 runtime 直讀 ACF
     * （如 OrderTypeService::getAutoWithdrawal），足跡 guard 攔不住未使用的活渠道；
     * 自助開分（2）為高風險自動渠道（cf 5，維運命令慣例硬排除，見 FixAgentCashFlowInfoKeys）。
     *
     * 空殼定義刻意最嚴：ACFOT 一筆都沒有（withTrashed 亦空）。有業務足跡的空殼
     * 是「ACFOT 被歷史手工清掉的真配置」，自動保留不刪；逐筆刪除仍走 Observer
     * deleting 的競態防線（預檢後才出現的引用會被擋下並計入失敗）。
     */
    private function handleOrphans(bool $isDryRun, bool $isForce): int
    {
        $orphans = AgentCashFlow::query()
            ->whereDoesntHave('agentCashFlowOrderTypes', fn ($query) => $query->withTrashed())
            ->whereHas('cashFlow', fn ($query) => $query->where('type', CashFlowTypeEnum::DEPOSIT->value))
            ->orderBy('agent_group_id')
            ->orderBy('id')
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('✅ 無空殼代收 ACF（type=1 的存活 ACF 都有 ACFOT），無需處理');

            return self::SUCCESS;
        }

        $referenceMap = AgentCashFlow::businessReferenceCountsFor($orphans->pluck('id')->all());

        $rows = $orphans->map(function (AgentCashFlow $acf) use ($referenceMap): array {
            $references = $referenceMap[$acf->id] ?? [];

            return [
                'acf' => $acf,
                'deletable' => $references === [],
                'footprint' => collect($references)
                    ->map(fn (int $count, string $table): string => sprintf('%s:%d', $table, $count))
                    ->implode(', '),
            ];
        });

        $this->info(sprintf('🔍 空殼代收 ACF（type=1、零 ACFOT）共 %d 筆：', $rows->count()));
        $this->table(
            ['acf_id', 'group', 'currency', 'cf_id', 'name', 'status', 'updated_at', '業務足跡', '判定'],
            $rows->map(fn (array $row): array => [
                $row['acf']->id,
                $row['acf']->agent_group_id,
                $row['acf']->currency_id,
                $row['acf']->cash_flow_id,
                $row['acf']->name,
                $row['acf']->status,
                (string) $row['acf']->updated_at,
                $row['footprint'] !== '' ? $row['footprint'] : '—',
                $row['deletable'] ? '可刪' : '保留（有足跡）',
            ])->all(),
        );

        /** @var Collection<int, AgentCashFlow> $deletable */
        $deletable = $rows->filter(fn (array $row): bool => $row['deletable'])->pluck('acf');
        $keptCount = $rows->count() - $deletable->count();

        $this->line(sprintf('   可刪 %d 筆／有足跡保留 %d 筆', $deletable->count(), $keptCount));

        if ($deletable->isEmpty()) {
            $this->info('✅ 沒有可刪的空殼 ACF（全數有業務足跡，屬史料配置）');

            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->newLine();
            $this->comment('正式執行請移除 --dry-run');

            return self::SUCCESS;
        }

        if (!$isForce && !$this->confirm(sprintf('確定軟刪除 %d 筆空殼 ACF？（有足跡的 %d 筆自動保留）', $deletable->count(), $keptCount), false)) {
            $this->warn('已取消');

            return self::FAILURE;
        }

        $deleted = [];
        $failed = [];

        foreach ($deletable as $acf) {
            try {
                DB::transaction(fn () => $acf->delete());
                $deleted[] = $acf->id;
            } catch (Throwable $e) {
                $failed[] = ['id' => $acf->id, 'error' => $e->getMessage()];
                $this->warn(sprintf('⚠️  AgentCashFlow %d 刪除被擋下：%s', $acf->id, $e->getMessage()));
            }
        }

        Log::info('acf-delete: orphans batch', [
            'deleted_ids' => $deleted,
            'kept_referenced' => $keptCount,
            'failed' => $failed,
            'executed_by' => 'artisan:fix:acf-delete --orphans',
        ]);

        $this->newLine();
        $this->info(sprintf(
            '✅ 已軟刪除 %d 筆空殼 ACF（有足跡保留 %d 筆%s）',
            count($deleted),
            $keptCount,
            $failed !== [] ? sprintf('、失敗 %d 筆', count($failed)) : '',
        ));
        $this->comment('   還原單筆：php artisan fix:acf-delete --acf=<id> --restore');

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function handleDelete(AgentCashFlow $acf, bool $isDryRun, bool $isForce): int
    {
        if ($acf->trashed()) {
            $this->warn(sprintf('⚠️  AgentCashFlow %d 已是軟刪狀態（%s），無需重複刪除', $acf->id, $acf->deleted_at));

            return self::SUCCESS;
        }

        // 業務足跡預檢：有任何引用＝真配置，禁止刪除（Observer deleting 亦會再擋一次）
        $references = $acf->businessReferenceCounts();

        if ($references !== []) {
            $this->error(sprintf('❌ AgentCashFlow %d 存在業務引用，禁止刪除：', $acf->id));
            $this->table(
                ['引用表', '筆數'],
                collect($references)->map(fn (int $count, string $table): array => [$table, $count])->values()->all(),
            );
            $this->line('   有史料的是真配置；需要下架請改用停用（status=0，會連動關閉旗下 ACFOT）');

            return self::FAILURE;
        }

        $links = AgentCashFlowOrderType::query()
            ->where('agent_cash_flow_id', $acf->id)
            ->get(['id', 'order_type_id', 'status']);

        $this->info($isDryRun ? '🔍 預覽（DRY-RUN）：將軟刪除以下 ACF 及其 ACFOT' : '🔍 預覽：將軟刪除以下 ACF 及其 ACFOT');
        $this->table(
            ['acf_id', 'name', 'status', '連動 ACFOT'],
            [[$acf->id, $acf->name, $acf->status, $links->pluck('id')->implode(', ') ?: '（無）']],
        );

        if ($isDryRun) {
            $this->newLine();
            $this->comment('正式執行請移除 --dry-run');

            return self::SUCCESS;
        }

        if (!$isForce && !$this->confirm(sprintf('確定軟刪除 AgentCashFlow %d（連動 %d 筆 ACFOT）？', $acf->id, $links->count()), false)) {
            $this->warn('已取消');

            return self::FAILURE;
        }

        try {
            DB::transaction(fn () => $acf->delete());

            Log::info('acf-delete: soft deleted', [
                'agent_cash_flow_id' => $acf->id,
                'acfot_ids' => $links->pluck('id')->all(),
                'executed_by' => 'artisan:fix:acf-delete',
            ]);

            $this->newLine();
            $this->info(sprintf('✅ 已軟刪除 AgentCashFlow %d（連動 %d 筆 ACFOT）', $acf->id, $links->count()));
            $this->comment(sprintf('   還原：php artisan fix:acf-delete --acf=%d --restore', $acf->id));

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('acf-delete: delete failed', [
                'agent_cash_flow_id' => $acf->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            $this->error(sprintf('❌ 執行失敗: %s', $e->getMessage()));

            return self::FAILURE;
        }
    }

    private function handleRestore(AgentCashFlow $acf, bool $isDryRun, bool $isForce): int
    {
        if (!$acf->trashed()) {
            $this->error(sprintf('❌ AgentCashFlow %d 未被軟刪，無可還原', $acf->id));

            return self::FAILURE;
        }

        $pendingCount = AcfCascadeDeactivateLog::query()
            ->where('agent_cash_flow_id', $acf->id)
            ->where('action', AcfCascadeDeactivateLog::ACTION_SOFT_DELETE)
            ->whereNull('restored_by_log_id')
            ->count();

        $this->info($isDryRun ? '🔍 預覽（DRY-RUN）：將還原以下 ACF' : '🔍 預覽：將還原以下 ACF');
        $this->table(
            ['acf_id', 'name', '刪除時間', '待還原審計列'],
            [[$acf->id, $acf->name, (string) $acf->deleted_at, $pendingCount]],
        );

        if ($isDryRun) {
            $this->newLine();
            $this->comment('正式執行請移除 --dry-run');

            return self::SUCCESS;
        }

        if (!$isForce && !$this->confirm(sprintf('確定還原 AgentCashFlow %d？', $acf->id), false)) {
            $this->warn('已取消');

            return self::FAILURE;
        }

        try {
            DB::transaction(fn () => $acf->restore());

            Log::info('acf-delete: restored', [
                'agent_cash_flow_id' => $acf->id,
                'executed_by' => 'artisan:fix:acf-delete',
            ]);

            $this->newLine();
            $this->info(sprintf('✅ 已還原 AgentCashFlow %d（連動軟刪的 ACFOT 一併復活、status 原樣保留）', $acf->id));
            $this->comment('   ACF status 維持 0：確認配置無誤後請自行啟用（status=1）');
            $this->comment('   若刪除期間已重建同 channel 配置，請先跑 agent:add-agent-cash-flow --dry-run 確認無重複');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('acf-delete: restore failed', [
                'agent_cash_flow_id' => $acf->id,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            $this->error(sprintf('❌ 執行失敗: %s', $e->getMessage()));

            return self::FAILURE;
        }
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
}
