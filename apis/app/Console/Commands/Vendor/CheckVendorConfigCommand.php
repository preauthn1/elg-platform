<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Models\Agent;
use App\Services\VendorService;
use Illuminate\Console\Command;

/**
 * 檢查遊戲商配置
 *
 * 用於排查 VendorInfo not found 問題
 */
class CheckVendorConfigCommand extends Command
{
    protected $signature = 'vendor:check-config
                            {vendor_id : Vendor ID}
                            {agent_id : Agent ID}
                            {--event-v2 : 是否為活動 V2 會員}';

    protected $description = '檢查遊戲商配置是否正確';

    public function handle(VendorService $vendorService): int
    {
        $vendorId = (int) $this->argument('vendor_id');
        $agentId = (int) $this->argument('agent_id');
        $isEventV2 = $this->option('event-v2');

        $this->info("檢查配置：Vendor ID = {$vendorId}, Agent ID = {$agentId}, Event V2 = " . ($isEventV2 ? 'Yes' : 'No'));
        $this->newLine();

        // 查詢代理
        $agent = Agent::find($agentId);
        if (!$agent) {
            $this->error("❌ Agent ID {$agentId} 不存在");

            return self::FAILURE;
        }

        $this->info('✅ 代理資訊：');
        $this->line("   - ID: {$agent->id}");
        $this->line("   - Username: {$agent->username}");
        $this->line("   - Vendor Customize Group ID: {$agent->vendor_customize_group_id}");
        $this->line('   - Hierarchy: ' . implode(' > ', $agent->hierarchy_array));
        $this->newLine();

        // 查詢 VendorInfo
        $vendorService->setIsEventWallet($isEventV2);
        $vendorInfo = $vendorService->getVendorInfo($agent, $vendorId);

        if (!$vendorInfo) {
            $this->error('❌ VendorInfo 不存在');
            $this->newLine();
            $this->warn('可能的原因：');
            $this->line("1. vendor_infos 表中沒有 vendor_id = {$vendorId} 且 status = 1 的記錄");
            $this->line('2. agent_vendors 表中沒有該代理鏈的配置');
            $this->line('3. vendor_customized_items 表中沒有對應的配置');
            $this->line('4. Event V2 類型不匹配（當前：' . ($isEventV2 ? 'Event V2' : 'Normal') . '）');
            $this->newLine();

            // 顯示相關記錄
            $this->info('相關 SQL 查詢：');
            $this->line('-- 檢查 vendor_infos');
            $this->line("SELECT * FROM vendor_infos WHERE vendor_id = {$vendorId} AND status = 1;");
            $this->newLine();
            $this->line('-- 檢查 agent_vendors');
            $agentIds = implode(',', $agent->hierarchy_array);
            $this->line("SELECT * FROM agent_vendors WHERE vendor_id = {$vendorId} AND agent_id IN ({$agentIds});");
            $this->newLine();
            $this->line('-- 檢查 vendor_customized_items');
            $this->line("SELECT * FROM vendor_customized_items WHERE vendor_id = {$vendorId} AND agent_group_id = {$agent->vendor_customize_group_id};");

            return self::FAILURE;
        }

        $this->info('✅ VendorInfo 找到：');
        $this->line("   - ID: {$vendorInfo->id}");
        $this->line("   - Name: {$vendorInfo->name}");
        $this->line("   - Status: {$vendorInfo->status}");
        $this->line("   - Type: {$vendorInfo->type}");
        $this->line("   - Account Prefix: {$vendorInfo->account_prefix}");
        $this->newLine();

        $this->info('✅ 配置正確！');

        return self::SUCCESS;
    }
}
