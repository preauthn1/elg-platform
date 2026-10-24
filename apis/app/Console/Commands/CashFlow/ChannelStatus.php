<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow;

use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use Illuminate\Console\Command;

/**
 * @knowledge rules/payment-channel-management
 */
class ChannelStatus extends Command
{
    protected $signature = 'cash-flow:channel-status
                            {cash_flow_id : 目標金流商 cash_flows.id}
                            {--acf= : 要改 status 的 agent_cash_flows.id}
                            {--acfot= : 要改 status 的 agent_cash_flow_order_types.id}
                            {--set-status= : 目標 status（0=停用 1=啟用）}
                            {--backend-display= : 目標 backend_display（0=後台隱藏 1=顯示），選用，不帶就不動}
                            {--force : 跳過執行前確認}';

    protected $description = '以 cash_flows.id 為根查／改渠道（ACF）與支付方式（ACFOT）上下架 status 與後台顯示';

    public function handle(): int
    {
        $cashFlowId = (int) $this->argument('cash_flow_id');
        $cashFlow = CashFlow::query()->find($cashFlowId);

        if ($cashFlow === null) {
            $this->error(sprintf('cash_flows #%d 不存在', $cashFlowId));

            return self::FAILURE;
        }

        if ($this->option('set-status') !== null || $this->option('backend-display') !== null) {
            return $this->applyChange($cashFlowId);
        }

        return $this->renderTree($cashFlow);
    }

    private function renderTree(CashFlow $cashFlow): int
    {
        $agentCashFlows = AgentCashFlow::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->with(['agentCashFlowOrderTypes.orderType'])
            ->orderBy('id')
            ->get();

        $rows = [];
        $rows[] = [sprintf('cash_flow #%d', $cashFlow->id), $cashFlow->name, $this->statusLabel($cashFlow->status), '-'];

        foreach ($agentCashFlows as $agentCashFlow) {
            $rows[] = [
                sprintf('├ ACF #%d', $agentCashFlow->id),
                $agentCashFlow->name,
                $this->statusLabel($agentCashFlow->status),
                $this->displayLabel($agentCashFlow->backend_display),
            ];

            foreach ($agentCashFlow->agentCashFlowOrderTypes as $acfot) {
                $rows[] = [
                    sprintf('│  └ ACFOT #%d', $acfot->id),
                    (string) ($acfot->orderType?->name ?? '-'),
                    $this->statusLabel($acfot->status),
                    $this->displayLabel($acfot->backend_display),
                ];
            }
        }

        $this->table(['節點', '名稱', '狀態', '後台顯示'], $rows);

        return self::SUCCESS;
    }

    /**
     * 改 --acf 或 --acfot 指定那一層的 status 與／或 backend_display
     *
     * 營運的上下架開關：代收在支付通道關聯（ACFOT），代付在金流商戶（ACF）；後台列表的 backend_display
     * 也照同一層讀。--backend-display 為選用，不帶就不動，停用前先隱藏時可以單獨帶。
     */
    private function applyChange(int $cashFlowId): int
    {
        $changes = [];

        foreach (['status' => 'set-status', 'backend_display' => 'backend-display'] as $column => $option) {
            if ($this->option($option) === null) {
                continue;
            }

            $value = (int) $this->option($option);

            if (!in_array($value, [0, 1], true)) {
                $this->error(sprintf('--%s 僅接受 0 或 1', $option));

                return self::FAILURE;
            }

            $changes[$column] = $value;
        }

        $acfId = $this->option('acf') !== null ? (int) $this->option('acf') : null;
        $acfotId = $this->option('acfot') !== null ? (int) $this->option('acfot') : null;

        if (($acfId === null) === ($acfotId === null)) {
            $this->error('請擇一指定 --acf 或 --acfot');

            return self::FAILURE;
        }

        [$label, $model] = $acfotId !== null
            ? [sprintf('ACFOT #%d', $acfotId), AgentCashFlowOrderType::query()->where('id', $acfotId)->where('cash_flow_id', $cashFlowId)->first()]
            : [sprintf('ACF #%d', $acfId), AgentCashFlow::query()->where('id', $acfId)->where('cash_flow_id', $cashFlowId)->first()];

        if ($model === null) {
            $this->error(sprintf('%s 不存在或不屬於 cash_flow #%d', $label, $cashFlowId));

            return self::FAILURE;
        }

        $changes = array_filter($changes, fn (int $value, string $column): bool => (int) $model->{$column} !== $value, ARRAY_FILTER_USE_BOTH);

        if ($changes === []) {
            $this->info(sprintf('%s 已是目標值，無需變更', $label));

            return self::SUCCESS;
        }

        $summary = implode('、', array_map(
            fn (string $column, int $value): string => sprintf('%s %d → %d', $column, (int) $model->{$column}, $value),
            array_keys($changes),
            $changes,
        ));

        if (!$this->option('force') && !$this->confirm(sprintf('確認將 %s %s？', $label, $summary), false)) {
            $this->info('已取消');

            return self::SUCCESS;
        }

        $model->update($changes);
        $this->info(sprintf('✅ %s %s', $label, $summary));

        return self::SUCCESS;
    }

    private function statusLabel(int $status): string
    {
        return $status === 1 ? '✓ 上架' : '✗ 下架';
    }

    private function displayLabel(int $backendDisplay): string
    {
        return $backendDisplay === 1 ? '顯示' : '隱藏';
    }
}
