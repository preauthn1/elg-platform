<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\AgentVendor;
use App\Services\VendorService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * php artisan vendor:export_agent_vendor_ids_matrix 35579
 * php artisan vendor:export_agent_vendor_ids_matrix 35823
 */
class ExportAgentVendorIdsMatrix extends Command
{
    protected $signature = 'vendor:export_agent_vendor_ids_matrix {agentId}';

    protected $description = '匯出指定代理及其所有下線的 VendorService::getVendorIds 結果矩陣 CSV（橫軸遊戲商，縱軸代理）';

    public function handle(VendorService $vendorService): int
    {
        $agentId = (int) $this->argument('agentId');
        $agent = Agent::query()->find($agentId);

        if (!$agent) {
            $this->error('Agent 不存在：' . $agentId);

            return self::FAILURE;
        }

        $agents = Agent::query()
            ->where('id', $agent->id)
            ->orWhere('hierarchy_path', 'like', $agent->hierarchy_path . '/%')
            ->orderBy('hierarchy_path')
            ->get();

        $agentIds = $agents->pluck('id')->toArray();
        $agentVendorAgentIds = AgentVendor::query()
            ->whereIn('agent_id', $agentIds)
            ->distinct()
            ->pluck('agent_id')
            ->toArray();
        $agents = $agents->whereIn('id', $agentVendorAgentIds);

        $this->info('找到 ' . $agents->count() . ' 個代理（含自身）');

        $vendorIdsByAgent = [];
        $vendorIdSet = [];
        $skipped = [];

        $this->withProgressBar($agents, function (Agent $a) use (&$vendorIdsByAgent, &$vendorIdSet, &$skipped) {
            $vendorIds = AgentVendor::query()
                ->where('agent_id', $a->id)
                ->where(function ($query) {
                    $query->where('status', 1)
                        ->orWhere('self_status', 1);
                })
                ->pluck('vendor_id')
                ->toArray();
            $vendorIdsByAgent[$a->id] = array_flip($vendorIds);
            foreach ($vendorIds as $vendorId) {
                $vendorIdSet[$vendorId] = true;
            }
        });
        $this->newLine();

        if ($skipped !== []) {
            $this->warn('略過 ' . count($skipped) . ' 個代理（hierarchy_path 異常或查詢失敗）：' . implode(', ', $skipped));
            $agents = $agents->whereNotIn('id', array_map(fn ($s) => (int) strtok($s, '('), $skipped));
        }

        $vendorIds = array_keys($vendorIdSet);
        sort($vendorIds);

        $path = storage_path('logs/agent_vendor_ids_matrix_' . now()->format('YmdHis') . '.csv');
        $this->writeCsv($path, $agents, $vendorIds, $vendorIdsByAgent);

        $this->info('已匯出至：' . $path);

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Agent>  $agents
     * @param  array<int, int>  $vendorIds
     * @param  array<int, array<int, int>>  $vendorIdsByAgent
     */
    private function writeCsv(string $path, $agents, array $vendorIds, array $vendorIdsByAgent): void
    {
        $fp = fopen($path, 'w');
        if ($fp === false) {
            throw new RuntimeException('無法開啟檔案：' . $path);
        }

        // UTF-8 BOM，方便 Excel 直接打開
        fwrite($fp, "\xEF\xBB\xBF");

        $header = ['代理'];
        foreach ($vendorIds as $vendorId) {
            $vendorName = VendorIdEnum::tryFrom($vendorId)?->name ?? 'UNKNOWN';
            $header[] = $vendorName . '(' . $vendorId . ')';
        }
        fputcsv($fp, $header);

        foreach ($agents as $agent) {
            $row = [$agent->username . '(' . $agent->id . ')'];
            foreach ($vendorIds as $vendorId) {
                $row[] = isset($vendorIdsByAgent[$agent->id][$vendorId]) ? 1 : 0;
            }
            fputcsv($fp, $row);
        }

        fclose($fp);
    }
}
