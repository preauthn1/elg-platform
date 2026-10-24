<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * php artisan tool:command-all-db
 */
class CommandAllDb extends Command
{
    protected $signature = 'tool:command-all-db';

    protected $description = '全站更新支付密碼錯誤次數上限：agent_customized_settings=100、agent_setting_defines 預設=-1';

    public function handle(): int
    {
        $settingKey = AgentCustomizedSettingKeyEnum::PAYMENT_PWD_FAIL_COUNT_LIMIT;

        /** @var array<string, mixed> $dbs */
        $dbs = require app_path('Console/Commands/CashFlow/Data/site_environments.php');

        foreach (array_keys($dbs) as $connection) {
            try {
                DB::connection($connection)->transaction(function () use ($connection, $settingKey): void {
                    $defineUpdated = DB::connection($connection)
                        ->table('agent_setting_defines')
                        ->where('setting_id', $settingKey)
                        ->update(['default_value' => '100']);

                    $customizedUpdated = DB::connection($connection)
                        ->table('agent_customized_settings')
                        ->where('setting_key', $settingKey)
                        ->update(['value' => '100']);

                    $this->info(sprintf(
                        '%s 完成：agent_setting_defines %d 筆、agent_customized_settings %d 筆',
                        $connection,
                        $defineUpdated,
                        $customizedUpdated,
                    ));
                });
            } catch (Throwable $e) {
                $this->error("{$connection} 更新失敗：{$e->getMessage()}");

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
