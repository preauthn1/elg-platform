<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders;
use Illuminate\Console\Command;

class UpgradeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:upgrade {--silent : 不輸出任何資訊}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '升級腳本';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $totalUpdated = 0;

        // 更新 Vendor 資料
        $vendorUpdated = (new Seeders\SyncVendorSeeder)->run();
        $totalUpdated += $vendorUpdated;
        $this->info("更新 {$vendorUpdated} 個 Vendor");

        // 更新 CashFlow 資料
        $cashFlowUpdated = (new Seeders\SyncCashFlowsSeeder)->run();
        $totalUpdated += $cashFlowUpdated;
        $this->info("更新 {$cashFlowUpdated} 個 CashFlow");

        // 更新 Procedure 資料
        $spUpdated = (new Seeders\SyncProcedureSeeder)->run();
        $totalUpdated += $spUpdated;
        $this->info("更新 {$spUpdated} 個 Procedure");

        // 更新 AgentSettingDefines 資料
        $agentSettingDefinesUpdated = (new Seeders\SyncAgentSettingDefinesSeeder)->run();
        $totalUpdated += $agentSettingDefinesUpdated;
        $this->info("更新 {$agentSettingDefinesUpdated} 個 AgentSettingDefine");

        // 更新 AgentMenus 資料
        $agentMenusUpdated = (new Seeders\SyncAgentMenuSeeder)->run();
        $totalUpdated += $agentMenusUpdated;
        $this->info("更新 {$agentMenusUpdated} 個 AgentMenu");

        // 更新簡訊商資料
        $smsGatewaysUpdated = (new Seeders\SyncSmsGatewaySeeder)->run();
        $totalUpdated += $smsGatewaysUpdated;
        $this->info("更新 {$smsGatewaysUpdated} 個 SmsGateway");

        // 更新國家資料
        $countriesUpdated = (new Seeders\SyncCountriesSeeder)->run();
        $totalUpdated += $countriesUpdated;
        $this->info("更新 {$countriesUpdated} 個 Country");

        // 更新國家資料
        $currenciesUpdated = (new Seeders\SyncCurrenciesSeeder)->run();
        $totalUpdated += $currenciesUpdated;
        $this->info("更新 {$currenciesUpdated} 個 Country");

        // 更新標準銀行資料
        $standardBanksUpdated = (new Seeders\SyncStandardBanksSeeder)->run();
        $totalUpdated += $standardBanksUpdated;
        $this->info("更新 {$standardBanksUpdated} 個 StandardBank");

        $this->info("升級完成，共更新 {$totalUpdated} 筆資料");
    }
}
