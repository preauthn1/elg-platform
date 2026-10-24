<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Models\Agent;
use App\Models\AgentCustomizedSetting;
use App\Models\AgentSettingDefine;
use App\Services\AgentLoginRestrictionService;
use Illuminate\Console\Command;

/**
 * 長期未登入代理自動限制登入（tickets#1988）。
 *
 * 發生原因：代理帳號長期閒置未登入，存在被盜用風險，需依組別設定
 * 的天數門檻自動限制登入（agents.sys_lock = 1），解除走後台人工操作。
 *
 * 修正目的：取代 2024 年只部署到測試環境的 MySQL EVENT + SP_Check_Agent_Not_Login
 * （天數寫死、無設定、無紀錄），改由應用層排程依「代理長期未登入限制天數」
 * （agent_setting_defines 5003，0 = 不啟用）分組判斷。
 *
 * 觸發時機：每日排程（routes/console.php）；亦可人工執行。
 *
 * 冪等性與重跑安全性：只挑 sys_lock = 0 且超期者鎖定，鎖定經
 * AgentLoginRestrictionService 於 transaction 內重驗狀態，重跑不會重複鎖定
 * 或重複寫 agent_status_logs。
 *
 * 排除條件：last_login 為 NULL（從未登入，無基準時間）、
 * last_login = '1970-01-01 00:00:00'（萬用密碼開關 sentinel 值，非真實登入時間）。
 *
 * 紀錄：每筆鎖定寫 agent_status_logs（admin = 0 表系統，備註含天數）。
 *
 * 用法：
 *   php artisan agent:lock-inactive            # 實際鎖定
 *   php artisan agent:lock-inactive --dry-run  # 乾跑，只列出將鎖定名單
 */
class LockInactiveAgents extends Command
{
    private const string SENTINEL_LAST_LOGIN = '1970-01-01 00:00:00';

    protected $signature = 'agent:lock-inactive
                            {--dry-run : 乾跑模式，只列出將鎖定的代理，不寫入資料庫}';

    protected $description = '鎖定長期未登入的代理（天數依組別設定 5003，0 為不啟用）';

    public function __construct(
        private readonly AgentLoginRestrictionService $restrictionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $daysByGroup = $this->loadLockDaysByGroup();

        if ($daysByGroup === []) {
            $this->info('所有組別的「代理長期未登入限制天數」皆為 0（未啟用），不處理。');

            return self::SUCCESS;
        }

        $execute = !$this->option('dry-run');
        $lockedCount = 0;
        $plan = [];

        Agent::query()
            ->select(['id', 'username', 'customized_setting_group_id', 'last_login', 'sys_lock', 'status'])
            ->where('status', 1)
            ->where('sys_lock', 0)
            ->whereNotNull('last_login')
            ->where('last_login', '!=', self::SENTINEL_LAST_LOGIN)
            ->whereIn('customized_setting_group_id', array_keys($daysByGroup))
            ->chunkById(500, function ($agents) use ($daysByGroup, $execute, &$lockedCount, &$plan) {
                foreach ($agents as $agent) {
                    $days = $daysByGroup[$agent->customized_setting_group_id];

                    if ($agent->last_login > now()->subDays($days)->toDateTimeString()) {
                        continue;
                    }

                    if (!$execute) {
                        $plan[] = [$agent->id, $agent->username, $agent->last_login, $days];

                        continue;
                    }

                    if ($this->restrictionService->lockForInactivity($agent->id, $days)) {
                        $lockedCount++;
                    }
                }
            });

        if (!$execute) {
            $this->table(['agent_id', 'username', 'last_login', '天數門檻'], $plan);
            $this->warn(sprintf('dry-run：共 %d 筆將被限制登入，移除 --dry-run 實際執行。', count($plan)));

            return self::SUCCESS;
        }

        $this->info(sprintf('已限制登入 %d 筆長期未登入代理。', $lockedCount));

        return self::SUCCESS;
    }

    /**
     * 取各組別的未登入限制天數（只保留 > 0 的組別；無組別值者以 define 預設值計）。
     *
     * @return array<int, int> agent_group_id => days
     */
    private function loadLockDaysByGroup(): array
    {
        $settingKey = AgentCustomizedSettingKeyEnum::BACKEND_INACTIVE_LOCK_DAYS;

        $default = (int) AgentSettingDefine::query()
            ->where('setting_id', $settingKey)
            ->value('default_value');

        $daysByGroup = AgentCustomizedSetting::query()
            ->where('setting_key', $settingKey)
            ->pluck('value', 'agent_group_id')
            ->map(fn ($value) => (int) $value)
            ->all();

        if ($default > 0) {
            $groupsWithoutValue = Agent::query()
                ->distinct()
                ->whereNotNull('customized_setting_group_id')
                ->whereNotIn('customized_setting_group_id', array_keys($daysByGroup))
                ->pluck('customized_setting_group_id');

            foreach ($groupsWithoutValue as $groupId) {
                $daysByGroup[$groupId] = $default;
            }
        }

        return array_filter($daysByGroup, fn (int $days) => $days > 0);
    }
}
