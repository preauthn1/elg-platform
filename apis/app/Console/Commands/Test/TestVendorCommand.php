<?php

declare(strict_types=1);

namespace App\Console\Commands\Test;

use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\Vendor;
use App\Models\VendorInfo;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

abstract class TestVendorCommand extends Command
{
    protected int $vendorId;

    protected int $agentId;

    protected function checkAgent($agentId): Agent
    {
        $this->line('檢查 agent');

        $agent = Agent::query()->find($agentId);

        if (!$agent) {
            throw new Exception('agent 不存在');
        }

        return $agent;
    }

    protected function checkVendor(int $vendorId): Vendor
    {
        $this->line('檢查 vendor');

        $vendor = Vendor::query()->find($vendorId);

        if (!$vendor) {
            $this->warn('請將遊戲商配置在 database/data/vendors.php 後升級');
            throw new Exception('vendor 不存在');
        }

        if ($vendor->status != 1) {
            $sql = sprintf('UPDATE `vendor` SET `status` = 1 WHERE `id` = %s', $vendorId);
            $this->warn('請執行 SQL: ' . $sql);
            throw new Exception('vendor 狀態非啟用中');
        }

        return $vendor;
    }

    protected function checkVendorInfos(Vendor $vendor): Collection
    {
        $this->line('檢查 vendor_info: vendor_id=' . $vendor->id);

        $vendorInfos = VendorInfo::where('vendor_id', $vendor->id)->get();

        if ($vendorInfos->isEmpty()) {
            $this->error('vendor_info 不存在, 請執行下列命令來建立');
            $this->warn('php artisan tool:insert_vendor_info');
            throw new Exception;
        }

        $count = count($vendorInfos);
        $enableCount = 0;
        foreach ($vendorInfos as $info) {
            if ($info->status == 1) {
                $enableCount++;
            }
        }

        $this->line(sprintf('vendor_info 共 %s 筆, 其中 %s 筆啟用中', $count, $enableCount));

        if ($enableCount == 0) {
            $this->error('vendor_info 狀態非啟用中, 請執行以下 SQL');
            $sql = sprintf('UPDATE `vendor_info` SET `status` = 1 WHERE `vendor_id` = %s', $vendor->id);
            $this->warn($sql);
            throw new Exception;
        }

        $this->info('通過');

        return $vendorInfos;
    }

    protected function checkAgentVendors(array $agentIds, $vendorInfos): Collection
    {
        $this->line('檢查 agent_vendor');

        $vendorInfoIds = $vendorInfos->pluck('id')->toArray();

        $agentVendors = null;
        $agentId = null;
        foreach ($agentIds as $agentId) {
            $this->line(sprintf('檢查 agent_vendor, agent_id: %s', $agentId));
            $agentVendors = AgentVendor::query()
                ->where('agent_id', $agentId)
                ->where('vendor_id', $this->vendorId)
                ->whereIn('vendor_info_id', $vendorInfoIds)
                ->get();

            if ($agentVendors->isEmpty()) {
                $this->line(sprintf('agent_id %s 缺少 agent_vendor', $agentId));
            } else {
                // 找到一個 agent_game 就跳出迴圈
                break;
            }
        }

        // 所有 agent_id 都沒有 agent_vendor
        if (!$agentVendors || $agentVendors->isEmpty()) {
            $this->error('agent_vendor 不存在, 請執行下列命令來建立');
            $this->warn(sprintf('php artisan tool:insert_agent_vendor %s %s %s', $this->vendorId, $agentId, $vendorInfoIds[0]));
            throw new Exception;
        }

        $count = $agentVendors->count();
        $enableCount = $agentVendors->filter(function ($vendor) use ($agentId) {
            if ($agentId !== $this->agentId) {
                return $vendor->status == 1;
            }

            return $vendor->status == 1 && $vendor->self_status == 1;
        })->count();
        $this->line(sprintf('agent_vendor 共 %s 筆, 其中 %s 筆啟用中', $count, $enableCount));

        if ($enableCount == 0) {
            $this->error(sprintf('agent_id %s 的 agent_vendor 狀態非啟用中', $agentId));
            $sql = sprintf('UPDATE `agent_vendor` SET `status` = 1 WHERE `agent_id` = %s AND `vendor_id` = %s;', $agentId, $this->vendorId);
            $this->warn($sql);
            throw new Exception;
        }

        $this->info('通過');

        return $agentVendors;
    }
}
