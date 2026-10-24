<?php

declare(strict_types=1);

namespace App\Console\Commands\Order;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugPayoutChannels extends Command
{
    protected $signature = 'withdrawal:debug-channels {agent_group_id} {currency_id}';

    protected $description = '调试代付渠道查询';

    public function handle(): int
    {
        $agentGroupId = (int) $this->argument('agent_group_id');
        $currencyId = (int) $this->argument('currency_id');

        $this->info('查询条件：');
        $this->line("  • agent_group_id: {$agentGroupId}");
        $this->line("  • currency_id: {$currencyId}");
        $this->newLine();

        // 1. 查询所有 agent_cash_flows
        $allAcf = DB::table('agent_cash_flows')
            ->where('agent_group_id', $agentGroupId)
            ->where('currency_id', $currencyId)
            ->get(['id', 'cash_flow_id', 'status', 'currency_id']);

        $this->info("1. agent_cash_flows 中符合条件的记录：{$allAcf->count()} 个");
        if ($allAcf->isNotEmpty()) {
            $this->table(
                ['ID', 'cash_flow_id', 'status', 'currency_id'],
                $allAcf->map(fn ($r) => [$r->id, $r->cash_flow_id, $r->status, $r->currency_id])
            );
        }
        $this->newLine();

        // 2. 查询 JOIN cash_flows 后的结果
        $withCashFlows = DB::table('agent_cash_flows as acf')
            ->join('cash_flows as c', 'acf.cash_flow_id', '=', 'c.id')
            ->where('acf.agent_group_id', $agentGroupId)
            ->where('acf.currency_id', $currencyId)
            ->get([
                'acf.id as acf_id',
                'acf.cash_flow_id',
                'acf.status as acf_status',
                'c.type as cf_type',
                'c.status as cf_status',
                'c.name as cf_name',
            ]);

        $this->info("2. JOIN cash_flows 后的记录：{$withCashFlows->count()} 个");
        if ($withCashFlows->isNotEmpty()) {
            $this->table(
                ['ACF_ID', 'CF_ID', 'ACF_Status', 'CF_Type', 'CF_Status', 'CF_Name'],
                $withCashFlows->map(fn ($r) => [
                    $r->acf_id,
                    $r->cash_flow_id,
                    $r->acf_status,
                    $r->cf_type,
                    $r->cf_status,
                    $r->cf_name,
                ])
            );
        }
        $this->newLine();

        // 3. 完整查询（包含所有条件）
        $fullQuery = DB::table('agent_cash_flows as acf')
            ->join('cash_flows as c', function ($join) {
                $join->on('acf.cash_flow_id', '=', 'c.id')
                    ->where('c.type', 3)
                    ->where('c.status', 1);
            })
            ->where('acf.agent_group_id', $agentGroupId)
            ->where('acf.currency_id', $currencyId)
            ->where('acf.status', 1)
            ->whereNotIn('acf.cash_flow_id', [5, 6])
            ->get([
                'acf.id as acf_id',
                'c.id as cf_id',
                'c.name as cf_name',
                'acf.currency_id',
            ]);

        $this->info("3. 完整查询结果（所有条件）：{$fullQuery->count()} 个");
        if ($fullQuery->isNotEmpty()) {
            $this->table(
                ['ACF_ID', 'CF_ID', 'CF_Name', 'Currency_ID'],
                $fullQuery->map(fn ($r) => [$r->acf_id, $r->cf_id, $r->cf_name, $r->currency_id])
            );
        } else {
            $this->warn('没有符合所有条件的记录');
        }

        return self::SUCCESS;
    }
}
