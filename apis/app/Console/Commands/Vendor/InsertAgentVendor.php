<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\VendorInfo;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan vendor:insert_agent_vendor  83 33893 552
 *
 * 以下為測試站的代理
 * tpower -- frank  -- 4249
 * vworld -- NeilGameTest -- 59562
 * vworld -- devmy -- 59497
 */
class InsertAgentVendor extends Command
{
    protected $signature = 'vendor:insert_agent_vendor {vendorId} {agentId} {vendorInfoId}';

    protected $description = '新增指定代理與下線的 agent_vendors';

    public function handle(): void
    {
        $isTest = $this->confirm('是否啟用測試模式（不會寫入資料）？', true);

        if (!in_array(strtolower((string) config('database.default')), ['mysql', 'dev', 'testing'], true)) {
            // 確認要在正式環境執行
            $confirm = $this->confirm('目前資料庫連線為 ' . config('database.default') . '，確定要繼續？', false);

            if (!$confirm) {
                $this->info('腳本已終止');

                return;
            }
        }

        try {
            DB::transaction(function () use ($isTest) {
                $agentId = (int) $this->argument('agentId');
                $vendorId = (int) $this->argument('vendorId');
                $vendorInfoId = (int) $this->argument('vendorInfoId');

                $vendorInfo = VendorInfo::query()
                    ->where('id', $vendorInfoId)
                    ->where('vendor_id', $vendorId)
                    ->first();

                if (!$vendorInfo) {
                    throw new Exception('VendorInfo 不存在');
                }

                $agent = Agent::find($agentId);

                if (!$agent) {
                    throw new Exception('Agent 不存在');
                }

                // 取得代理及其下線的 ID (已有 agent_vendor 的)
                $existingAgentIds = DB::table('agents as a')
                    ->join('agent_vendors as av', 'a.id', '=', 'av.agent_id')
                    ->where('a.hierarchy_path', 'like', $agent->hierarchy_path . '/%')
                    ->distinct()
                    ->pluck('a.id')
                    ->toArray();

                // 合併所有需要處理的 agent_id (去重)
                $allAgentIds = array_unique(array_merge(
                    $existingAgentIds,
                    [$agentId, 1]
                ));

                // 取得已建立此遊戲商的 agent_vendor
                $existingVendors = AgentVendor::query()
                    ->whereIn('agent_id', $allAgentIds)
                    ->where('vendor_id', $vendorId)
                    ->where('vendor_info_id', $vendorInfoId)
                    ->pluck('agent_id')
                    ->toArray();

                // 準備新增資料
                $insertData = [];
                foreach ($allAgentIds as $id) {
                    if (in_array($id, $existingVendors, true)) {
                        continue;
                    }

                    $insertData[] = [
                        'agent_id' => $id,
                        'vendor_id' => $vendorId,
                        'vendor_info_id' => $vendorInfoId,
                        'status' => 1,
                        'self_status' => $id === 1 ? 0 : 1, // admin 不用開啟
                        'backend_show' => 1,
                        'admin' => 1,
                    ];
                }

                if (empty($insertData)) {
                    throw new Exception('沒有需要新增的資料');
                }

                AgentVendor::query()->insert($insertData);
                $this->info(sprintf('新增 agent_vendor, 共 %s 筆', count($insertData)));

                // 測試模式拋出例外觸發回滾
                if ($isTest) {
                    throw new Exception('測試模式,資料已回滾');
                }
            });

            $this->info('腳本已執行完成');
        } catch (Exception $e) {
            if ($isTest) {
                $this->info('腳本已執行完成,所有資料已回滾,未寫入資料庫。');
            } else {
                $this->error('執行失敗: ' . $e->getMessage());
            }
        }
    }
}
