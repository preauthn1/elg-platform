<?php

declare(strict_types=1);

namespace App\Console\Commands\Tool;

use App\Models\AgentCustomizedSetting;
use App\Models\AgentPersonalSetting;
use App\Models\AgentSettingDefine;
use App\Models\LanguageText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 刪除指定 agent_setting_define 及其所有關聯資料。
 * 操作範圍：所有專案資料庫連線。
 *
 * 關聯資料表（各站分庫）：
 *   - agent_personal_settings  (setting_id = ?)
 *   - agent_customized_settings (setting_key = ?)
 *   - language_texts           (language_text_code = agent_setting_define.language_text_code)
 *
 * 試跑（只顯示影響數量，不實際刪除）：
 *   php artisan tool:delete-agent-setting-define 2520 --dry-run
 *
 * 正式執行：
 *   php artisan tool:delete-agent-setting-define 2520
 *
 * 只跑指定連線：
 *   php artisan tool:delete-agent-setting-define {id} --connection=VWorld
 */
class DeleteAgentSettingDefine extends Command
{
    protected $signature = 'tool:delete-agent-setting-define
                            {id : agent_setting_defines.setting_id}
                            {--connection= : 只處理指定連線名稱}
                            {--dry-run : 只顯示影響數量，不實際刪除}';

    protected $description = '刪除指定 agent_setting_define 及全站關聯的 agent_personal_settings、agent_customized_settings、language_texts';

    /** @var list<string> */
    private array $projectConnections = [
        'mysql',
        'VWorld',
        'TPower',
        'VPlus33',
        'Yes2Win',
        'Alfawin77',
        'Me77',
    ];

    public function handle(): int
    {
        $settingId = (int) $this->argument('id');
        $isDryRun = (bool) $this->option('dry-run');
        $onlyConnection = $this->option('connection');

        $connections = $this->resolveConnections(
            is_string($onlyConnection) && $onlyConnection !== '' ? $onlyConnection : null,
        );

        if ($connections === []) {
            $this->error('找不到可用的資料庫連線');

            return Command::FAILURE;
        }

        if ($isDryRun) {
            $this->warn('※ Dry-run 模式：只顯示影響數量，不實際刪除');
            $this->newLine();
        } elseif (!$this->confirmExecution($settingId, $connections)) {
            $this->info('指令已取消');

            return Command::SUCCESS;
        }

        $totalDefines = 0;
        $totalPersonal = 0;
        $totalCustomized = 0;
        $totalLanguageTexts = 0;

        foreach ($connections as $connectionName) {
            [$defines, $personal, $customized, $languageTexts] = $this->processConnection(
                connectionName: $connectionName,
                settingId: $settingId,
                isDryRun: $isDryRun,
            );

            $totalDefines += $defines;
            $totalPersonal += $personal;
            $totalCustomized += $customized;
            $totalLanguageTexts += $languageTexts;
        }

        $this->newLine();

        $label = $isDryRun ? 'Dry-run 完成：預計刪除' : '完成：共刪除';
        $this->info(implode('、', [
            "{$label} agent_setting_defines {$totalDefines} 筆",
            "agent_personal_settings {$totalPersonal} 筆",
            "agent_customized_settings {$totalCustomized} 筆",
            "language_texts {$totalLanguageTexts} 筆",
        ]));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveConnections(?string $only): array
    {
        $defined = array_keys(config('database.connections'));
        $project = array_values(array_intersect($this->projectConnections, $defined));

        if ($only !== null) {
            return in_array($only, $project, true) ? [$only] : [];
        }

        return $project;
    }

    /**
     * @param  list<string>  $connections
     */
    private function confirmExecution(int $settingId, array $connections): bool
    {
        return $this->confirm(
            "將從以下連線刪除 setting_id={$settingId} 的 AgentSettingDefine 及所有關聯資料：" . implode(', ', $connections) . '。確定繼續？',
            false,
        );
    }

    /**
     * @return array{int, int, int, int} [$defines, $personal, $customized, $languageTexts]
     */
    private function processConnection(string $connectionName, int $settingId, bool $isDryRun): array
    {
        $this->info("── 連線：{$connectionName}");

        try {
            $define = AgentSettingDefine::on($connectionName)
                ->where('setting_id', $settingId)
                ->first(['setting_id', 'language_text_code']);

            $defineCount = $define !== null ? 1 : 0;

            $personalCount = AgentPersonalSetting::on($connectionName)
                ->where('setting_id', $settingId)
                ->count();

            $customizedCount = AgentCustomizedSetting::on($connectionName)
                ->where('setting_key', $settingId)
                ->count();

            $languageTextCount = $define !== null
                ? LanguageText::on($connectionName)
                    ->where('language_text_code', $define->language_text_code)
                    ->count()
                : 0;

            $this->line("   agent_setting_defines：{$defineCount} 筆");
            $this->line("   agent_personal_settings：{$personalCount} 筆");
            $this->line("   agent_customized_settings：{$customizedCount} 筆");
            $this->line("   language_texts (code={$define?->language_text_code})：{$languageTextCount} 筆");

            if ($isDryRun) {
                return [$defineCount, $personalCount, $customizedCount, $languageTextCount];
            }

            DB::connection($connectionName)->transaction(function () use ($connectionName, $settingId, $define): void {
                AgentPersonalSetting::on($connectionName)
                    ->where('setting_id', $settingId)
                    ->delete();

                AgentCustomizedSetting::on($connectionName)
                    ->where('setting_key', $settingId)
                    ->delete();

                if ($define !== null) {
                    LanguageText::on($connectionName)
                        ->where('language_text_code', $define->language_text_code)
                        ->delete();
                }

                AgentSettingDefine::on($connectionName)
                    ->where('setting_id', $settingId)
                    ->delete();
            });

            $this->line('   已刪除完成');

            return [$defineCount, $personalCount, $customizedCount, $languageTextCount];
        } catch (Throwable $e) {
            $this->error("   {$connectionName} 處理失敗：{$e->getMessage()}");

            return [0, 0, 0, 0];
        }
    }
}
