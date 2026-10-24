<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use App\Models\AgentLoginIpLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 由 agent_login_ip_logs 取每位代理最早一筆登入 IP，回填 agents.first_login_ip。
 *
 * 【分類】Maintenance — 可複用回填工具，**目前是該欄位的唯一寫入來源**。
 *
 * ## 發生原因
 * `agents.first_login_ip` 於 `2026_05_05_000000_add_first_ip_to_agents_table` 加入，
 * migration 給的預設值是 `0`。真正的首登 IP 一直都記在 `agent_login_ip_logs`，本欄位只是
 * 為了免去每次查詢都要對 log 表做 MIN(id) 而做的便利欄位，因此新增當下全表皆為 0，需要回填。
 *
 * **即時寫入由舊系統 API_Agent 負責**（2026-07-27 查證）：
 * `API_Agent/_release/app/Http/Controllers/Auth/LoginController.php:255-256`
 *
 * ```php
 * if ($agent->first_login_ip == 0) {
 *     $agent_update['first_login_ip'] = $ip;
 * ```
 *
 * 代理登入流程尚未遷入 APIs，因此**本 repo 內找不到寫入路徑是預期的、不是缺口**；
 * 且舊系統的寫入條件（只在 0 時寫）與本命令的 `WHERE first_login_ip = 0` 語意一致，兩者不會打架。
 *
 * ## 修正目的
 * 以 `agent_login_ip_logs` 每個 agent 的 `MIN(id)`（id 遞增即時間遞增）那筆的
 * `ip_1~ip_4` 組成 IPv4，經 `INET_ATON` 存入 `agents.first_login_ip`。
 *
 * ## 觸發時機
 * 1. **欄位上線後的初次回填**（主要用途——舊系統只寫「新發生的登入」，補不了歷史）
 * 2. 代理報表／風控查詢發現 `first_login_ip = 0` 的比例異常時（舊系統寫入失敗或漏寫的兜底）
 * 3. 舊系統登入流程異動後的複驗
 *
 * ## 冪等性與重跑安全性
 * **完全冪等，重跑安全。** SQL 帶 `WHERE a.first_login_ip = 0`，**只補未設定的列、
 * 不覆寫任何已有值**——即使 log 表後續新增資料，也不會改動既有回填結果。
 *
 * 以 id 視窗每 500,000 筆分批 UPDATE，避免大表長交易鎖定；欄位不存在時先報錯退出。
 *
 * ⚠️ 無 `--dry-run`：本命令只寫 0 值列、且值來自 log 表推導，誤傷面極小；若要先評估筆數，
 * 可先查 `SELECT COUNT(*) FROM agents WHERE first_login_ip = 0`。
 *
 * ## 退役條件
 * 六站各跑一次、確認 `SELECT COUNT(*) FROM agents WHERE first_login_ip = 0` 只剩「確實從未
 * 登入過」的代理後即可刪除——後續由舊系統的登入流程自然維持。
 *
 * ⚠️ **代理登入遷入 APIs 時的檢查點**：新實作必須沿用同一條寫入邏輯
 * （首登時 `first_login_ip == 0` 才寫），否則欄位會從遷移那天起停止更新。
 * 遷移前不要先刪本命令，它是遷移期間的兜底。
 *
 * ## 關聯
 * - **即時寫入端（舊系統）**：`API_Agent/_release/app/Http/Controllers/Auth/LoginController.php:255-256`
 * - 欄位來源：`database/migrations/2026_05_05_000000_add_first_ip_to_agents_table.php`
 * - 資料來源表：`app/Models/AgentLoginIpLog.php`
 *
 * 用法：
 *   php artisan patch:sync-agent-first-login-ip
 */
class SyncAgentFirstLoginIp extends Command
{
    protected $signature = 'patch:sync-agent-first-login-ip';

    protected $description = '由 AgentLoginIpLog 每個 agent 最早一筆登入 IP 回填 agents.first_login_ip';

    public function handle(): int
    {
        if (!Schema::hasColumn('agents', 'first_login_ip')) {
            $this->error('agents.first_login_ip 欄位不存在，請先執行 migration。');

            return self::FAILURE;
        }

        $updated = 0;
        $totalUpdated = 0;

        $maxId = AgentLoginIpLog::max('id');
        $idGroups = [];
        for ($i = 1; $i < $maxId; $i += 500000) {
            $idGroups[] = [
                'min' => $i,
                'max' => $i + 500000,
            ];
        }

        foreach ($idGroups as $index => $idGroup) {
            $sql = "UPDATE agents a
                    JOIN (
                        SELECT agent_id, MIN(id) AS first_id
                        FROM agent_login_ip_logs
                        WHERE id >= {$idGroup['min']} AND id < {$idGroup['max']}
                        GROUP BY agent_id
                    ) x
                        ON x.agent_id = a.id
                    JOIN agent_login_ip_logs l
                        ON l.id = x.first_id
                    SET a.first_login_ip = INET_ATON(CONCAT(l.ip_1, '.', l.ip_2, '.', l.ip_3, '.', l.ip_4))
                    WHERE a.first_login_ip = 0;";
            $updated = DB::update($sql);
            $totalUpdated += $updated;
            $this->info(sprintf('批次: %s, min: %s, max: %s, 更新筆數: %d, 累積更新筆數: %d', $index + 1, $idGroup['min'], $idGroup['max'], $updated, $totalUpdated));
        }

        return self::SUCCESS;
    }
}
