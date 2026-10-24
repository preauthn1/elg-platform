<?php

declare(strict_types=1);

namespace App\Console\Commands\Order;

use App\Enums\CashFlowTypeEnum;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 找出指定日期內 orders.tax > 0 的代收已付款訂單，
 * 並判斷該手續費的計算來源是 ACF (agent_cash_flows) 還是 ACFOT (agent_cash_flow_order_types)。
 *
 * 比對邏輯：
 *   expected_acf   = ACF.tax   + amount × rate × ACF.tax_percentage
 *   expected_acfot = ACFOT.tax + amount × rate × ACFOT.tax_percentage
 *
 *   - orders.tax == expected_acf   → 來源 ACF（OrderService::createOrder 寫入）
 *   - orders.tax == expected_acfot → 來源 ACFOT（TngPin::DepositCallback 覆寫）
 *   - 兩者都不符                    → UNKNOWN（資料異常或計算邏輯不在預期之內）
 *
 * 日期過濾走 orders.pay_time（idx_orders_pay_time），語意為「指定日期已付款的代收訂單」。
 *
 * php artisan order:check-deposit-tax-applied
 * php artisan order:check-deposit-tax-applied --date=2026-05-22
 */
class CheckDepositTaxApplied extends Command
{
    protected $signature = 'order:check-deposit-tax-applied
        {--date= : 查詢日期 YYYY-MM-DD（依 pay_time），預設今日}';

    protected $description = '找出代收已付款訂單 tax > 0，判斷手續費來源是 ACF 或 ACFOT';

    public function handle(): int
    {
        $date = $this->resolveDate();

        if ($date === null) {
            return self::FAILURE;
        }

        $this->info("查詢日期：{$date->toDateString()}");
        $this->newLine();

        $orders = $this->loadTaxedDepositOrders($date);

        $this->info("代收已付款 + tax > 0 訂單：{$orders->count()} 筆");

        if ($orders->isEmpty()) {
            $this->info('該日期無 tax > 0 訂單');

            return self::SUCCESS;
        }

        $acfotMap = $this->loadAcfotMap($orders);
        $rows = $orders->map(fn (Order $order) => $this->classifyOrder($order, $acfotMap));

        $this->printSummary($rows);
        $this->newLine();
        $this->printTable($rows);

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Order>
     */
    private function loadTaxedDepositOrders(Carbon $date): Collection
    {
        return $this->depositOrdersBaseQuery($date)
            ->where('tax', '>', 0)
            ->with([
                'agentCashFlow:id,name,cash_flow_id,tax,tax_percentage',
                'agentCashFlow.cashFlow:id,name',
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return Collection<string, AgentCashFlowOrderType>
     */
    private function loadAcfotMap(Collection $orders): Collection
    {
        $acfIds = $orders->pluck('agent_cash_flow_id')->unique();
        $orderTypeIds = $orders->pluck('order_type_id')->unique();

        return AgentCashFlowOrderType::query()
            ->whereIn('agent_cash_flow_id', $acfIds)
            ->whereIn('order_type_id', $orderTypeIds)
            ->get(['agent_cash_flow_id', 'order_type_id', 'tax', 'tax_percentage'])
            ->keyBy(fn (AgentCashFlowOrderType $row) => "{$row->agent_cash_flow_id}:{$row->order_type_id}");
    }

    /**
     * @param  Collection<string, AgentCashFlowOrderType>  $acfotMap
     * @return array{order: Order, source: string, expected_acf: string, expected_acfot: ?string, acf: ?AgentCashFlow, acfot: ?AgentCashFlowOrderType}
     */
    private function classifyOrder(Order $order, Collection $acfotMap): array
    {
        $acf = $order->agentCashFlow;
        $acfot = $acfotMap->get("{$order->agent_cash_flow_id}:{$order->order_type_id}");

        $expectedAcf = $this->calcExpectedTax(
            amount: (string) $order->amount,
            rate: (string) $order->rate,
            taxFixed: (string) ($acf?->tax ?? '0'),
            taxPercentage: (string) ($acf?->tax_percentage ?? '0'),
        );

        $expectedAcfot = $acfot
            ? $this->calcExpectedTax(
                amount: (string) $order->amount,
                rate: (string) $order->rate,
                taxFixed: (string) $acfot->tax,
                taxPercentage: (string) $acfot->tax_percentage,
            )
            : null;

        $source = match (true) {
            Common::numCompare((string) $order->tax, $expectedAcf, 4) === 0 => 'ACF',
            $expectedAcfot !== null && Common::numCompare((string) $order->tax, $expectedAcfot, 4) === 0 => 'ACFOT',
            default => 'UNKNOWN',
        };

        return [
            'order' => $order,
            'source' => $source,
            'expected_acf' => $expectedAcf,
            'expected_acfot' => $expectedAcfot,
            'acf' => $acf,
            'acfot' => $acfot,
        ];
    }

    private function calcExpectedTax(string $amount, string $rate, string $taxFixed, string $taxPercentage): string
    {
        $point = Common::numMul($amount, $rate, 4);
        $percentage = Common::numMul($point, $taxPercentage, 4);

        return Common::numAdd($taxFixed, $percentage, 4);
    }

    /**
     * @param  Collection<int, array>  $rows
     */
    private function printSummary(Collection $rows): void
    {
        $counts = $rows->groupBy('source')->map->count();

        $this->info('手續費來源分類：');
        $this->table(
            ['source', 'count'],
            collect(['ACF', 'ACFOT', 'UNKNOWN'])->map(fn (string $key) => [
                $key,
                $counts->get($key, 0),
            ])->all(),
        );
    }

    /**
     * @param  Collection<int, array>  $rows
     */
    private function printTable(Collection $rows): void
    {
        $this->table(
            ['order_id', 'order_no', 'amount', 'rate', 'orders.tax', 'source', 'provider', 'acf.tax', 'acf.tax%', 'expected_acf', 'acfot.tax', 'acfot.tax%', 'expected_acfot', 'pay_time'],
            $rows->map(function (array $row) {
                /** @var Order $order */
                $order = $row['order'];
                $acf = $row['acf'];
                $acfot = $row['acfot'];

                return [
                    $order->id,
                    $order->order_no,
                    $order->amount,
                    $order->rate,
                    $order->tax,
                    $row['source'],
                    $acf?->cashFlow?->name ?? '—',
                    $acf?->tax ?? '—',
                    $acf?->tax_percentage ?? '—',
                    $row['expected_acf'],
                    $acfot?->tax ?? '—',
                    $acfot?->tax_percentage ?? '—',
                    $row['expected_acfot'] ?? '—',
                    $order->pay_time ?? '—',
                ];
            })->all(),
        );
    }

    private function depositOrdersBaseQuery(Carbon $date): Builder
    {
        // orders.cash_flow_id 可能為 NULL（TngPin PIN 模式部分情境），
        // 改透過 agent_cash_flows.cash_flow_id 反查 cash_flows.type=1 判斷代收。
        return Order::query()
            ->where('pay_time', '>=', $date->copy()->startOfDay())
            ->where('pay_time', '<=', $date->copy()->endOfDay())
            ->whereExists(function ($sub) {
                $sub->from('agent_cash_flows as acf_for_deposit_check')
                    ->join('cash_flows as cf_for_deposit_check', 'cf_for_deposit_check.id', '=', 'acf_for_deposit_check.cash_flow_id')
                    ->whereColumn('acf_for_deposit_check.id', 'orders.agent_cash_flow_id')
                    ->where('cf_for_deposit_check.type', CashFlowTypeEnum::DEPOSIT->value);
            });
    }

    private function resolveDate(): ?Carbon
    {
        $input = $this->option('date');

        if ($input === null || $input === '') {
            return Carbon::today();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $input)->startOfDay();
        } catch (\Throwable) {
            $this->error("--date 格式錯誤，請使用 YYYY-MM-DD（收到：{$input}）");

            return null;
        }
    }
}
