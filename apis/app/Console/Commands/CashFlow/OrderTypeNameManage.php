<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow;

use App\Models\CashFlow;
use App\Models\OrderTypeName;
use Illuminate\Console\Command;

class OrderTypeNameManage extends Command
{
    protected $signature = 'cash-flow:order-type-name
                            {cash_flow_id : 目標金流商 cash_flows.id}
                            {--otn= : 要改名的 order_type_names.id}
                            {--set-name= : 目標名稱}
                            {--force : 跳過執行前確認}';

    protected $description = '以 cash_flows.id 為根查／改支付方式多語系顯示名（order_type_names）';

    public function handle(): int
    {
        $cashFlowId = (int) $this->argument('cash_flow_id');

        if (CashFlow::query()->find($cashFlowId) === null) {
            $this->error(sprintf('cash_flows #%d 不存在', $cashFlowId));

            return self::FAILURE;
        }

        if ($this->option('set-name') !== null) {
            return $this->applyNameChange($cashFlowId);
        }

        return $this->renderList($cashFlowId);
    }

    private function renderList(int $cashFlowId): int
    {
        $names = OrderTypeName::query()
            ->where('cash_flow_id', $cashFlowId)
            ->orderBy('type_id')
            ->orderBy('language_id')
            ->get();

        if ($names->isEmpty()) {
            $this->info(sprintf('cash_flow #%d 底下無 order_type_names（或尚未 backfill cash_flow_id）', $cashFlowId));

            return self::SUCCESS;
        }

        $rows = $names->map(static fn (OrderTypeName $n): array => [
            (string) $n->id, (string) $n->type_id, (string) $n->language_id, $n->name,
        ])->all();

        $this->table(['OTN id', 'type_id', 'language_id', '名稱'], $rows);

        return self::SUCCESS;
    }

    private function applyNameChange(int $cashFlowId): int
    {
        $otnId = $this->option('otn') !== null ? (int) $this->option('otn') : null;

        if ($otnId === null) {
            $this->error('改名需指定 --otn');

            return self::FAILURE;
        }

        $newName = (string) $this->option('set-name');

        if (trim($newName) === '') {
            $this->error('--set-name 不可為空');

            return self::FAILURE;
        }

        $otn = OrderTypeName::query()
            ->where('id', $otnId)
            ->where('cash_flow_id', $cashFlowId)
            ->first();

        if ($otn === null) {
            $this->error(sprintf('order_type_names #%d 不存在或不屬於 cash_flow #%d', $otnId, $cashFlowId));

            return self::FAILURE;
        }

        if ($otn->name === $newName) {
            $this->info('名稱未變更');

            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm(sprintf('確認將 OTN #%d 名稱「%s」→「%s」？', $otnId, $otn->name, $newName), false)) {
            $this->info('已取消');

            return self::SUCCESS;
        }

        $otn->update(['name' => $newName, 'admin' => 0]);
        $this->info(sprintf('✅ OTN #%d 已改名為「%s」', $otnId, $newName));

        return self::SUCCESS;
    }
}
