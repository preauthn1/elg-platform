<?php

declare(strict_types=1);

namespace App\Console\Commands\SummaryBetRecord\SP;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Vendor\VendorIdEnum;
use Illuminate\Support\Facades\DB;

/**
 * 全民代理逐層彙總（summary_bet_records_team）——同名 SP（database/procedures/SP_summary_bet_record_team.sql）的忠實 PHP 移植
 *
 * ⚠️ 雙軌等值約束（target/tickets#4257）：舊系統 RD-Studio-01 每小時仍呼叫原 SP 對
 * 同一格刪除重建，本移植的產出必須與 SP 逐列一致（見等值測試
 * SummaryProcedurePortEquivalenceTest），否則兩邊互相覆蓋造成數值閃爍——
 * 雙軌收斂前禁止在此「順手優化」任何計算行為。與 SP 的刻意差異僅兩處：
 * 1. SQL EXIT HANDLER 發 TG 通知 → 改由 PHP 例外上拋，呼叫端（Runner）BotNotify
 * 2. 參數 NULL 時「預設前一小時」分支未移植——呼叫端一律傳明確 bucket
 *
 * 邏輯（對齊 SP 版本註記至 2025-04-15）：
 * - 自最深代理層級（MAX(member_agent.agent_level)）往上逐層插入，每層兩段 UNION：
 *   直屬會員 SBR 彙總（member_unlimit_cancels 當日 status=1 者 bet 歸零、win_lose 保留）
 *   ＋ 下一層（level+1）會員的 team 列滾算到其 topper_member_id（讀前一輪剛寫入的列）
 * - 僅計入「上層代理開啟全民代理模式」（agent_customized_settings 7001 = 1）的會員
 * - 918(3)/mega888(10)/pussy888(23) 整日重建：summary_time 用 DATE(start +8h)（台北日）
 * - 收尾寫 summary_control_tables（upsert 固定列 10006）＋ summary_control_logs 一筆
 */
class SP_summary_bet_record_team
{
    /** summary_control_tables 中本彙總的固定列 id（沿用 SP 的 10006） */
    private const int CONTROL_TABLE_ID = 10006;

    public function write(string $startTime): void
    {
        $execStart = date('Y-m-d H:i:s');
        $start = $startTime;
        $end = date('Y-m-d H:i:s', strtotime($start . ' +1 hour'));
        // 918 系整日格與 MUC 歸日基準：DATE(start +8h)（台北日）
        $summaryTime = date('Y-m-d 00:00:00', strtotime($start . ' +8 hours'));
        $settlementDate = $summaryTime;
        $maxLevel = (int) DB::table('member_agent')->max('agent_level');
        $dailyVendorIds = $this->dailyRebuildVendorIds();

        DB::delete(
            "DELETE FROM summary_bet_records_team WHERE summary_time = ? AND vendor_id NOT IN ({$dailyVendorIds})",
            [$start],
        );
        DB::delete(
            "DELETE FROM summary_bet_records_team WHERE summary_time = ? AND vendor_id IN ({$dailyVendorIds})",
            [$summaryTime],
        );

        for ($level = $maxLevel; $level > 0; $level--) {
            $this->insertHourlyLevel($level, $start, $end, $settlementDate, $dailyVendorIds);
            $this->insertDailyLevel($level, $summaryTime, $settlementDate, $dailyVendorIds);
        }

        $this->writeControlTable($start, $end, $execStart, date('Y-m-d H:i:s'));
    }

    /**
     * 一般遊戲商（小時格）：直屬彙總 ＋ 下一層 team 列滾算，寫入 summary_time = start。
     */
    private function insertHourlyLevel(int $level, string $start, string $end, string $settlementDate, string $dailyVendorIds): void
    {
        DB::insert(
            <<<SQL
            INSERT INTO summary_bet_records_team(
                summary_time, member_id, vendor_id, game_id,
                bet, bet_direct, bet_team,
                win_lose, win_lose_direct, win_lose_team, `admin`
            )
            SELECT ?
                 , D.member_id
                 , D.vendor_id
                 , D.game_id
                 , SUM(D.bet) AS bet
                 , SUM(D.bet_direct) AS bet_direct
                 , SUM(D.bet_team) AS bet_team
                 , SUM(D.win_lose) AS win_lose
                 , SUM(D.win_lose_direct) AS win_lose_direct
                 , SUM(D.win_lose_team) AS win_lose_team
                 , 0 AS `admin`
            FROM (
                     SELECT MA.member_id
                          , B.vendor_id
                          , B.game_id
                          , CASE MUC.`status` WHEN 1 THEN 0 ELSE SUM(B.bet) END AS bet
                          , SUM(B.win_lose) AS win_lose
                          , 0 AS bet_direct
                          , 0 AS win_lose_direct
                          , 0 AS bet_team
                          , 0 AS win_lose_team
                     FROM member_agent AS MA
                              JOIN agents AS A
                                   ON A.id = MA.parent_id
                              JOIN agent_customized_settings AS ACS
                                   ON ACS.agent_group_id = A.customized_setting_group_id
                                       AND setting_key = ?
                                       AND ACS.`value` = 1
                              JOIN summary_bet_records AS B
                                   ON B.member_id = MA.member_id
                              LEFT JOIN member_unlimit_cancels AS MUC
                                        ON MUC.member_id = MA.member_id
                                            AND `date` = ?
                     WHERE MA.agent_level = ?
                       AND B.summary_time >= ? AND B.summary_time < ?
                       AND B.vendor_id NOT IN ({$dailyVendorIds})
                     GROUP BY MA.member_id, B.vendor_id, B.game_id
                     UNION ALL
                     SELECT MA.topper_member_id AS member_id
                          , B.vendor_id
                          , B.game_id
                          , 0 AS bet
                          , 0 AS win
                          , SUM(B.bet) AS bet_direct
                          , SUM(B.win_lose) AS win_lose_direct
                          , SUM(B.bet_direct + B.bet_team) AS bet_team
                          , SUM(B.win_lose_direct + B.win_lose_team) AS win_lose_team
                     FROM member_agent AS MA
                              JOIN agents AS A
                                   ON A.id = MA.parent_id
                              JOIN agent_customized_settings AS ACS
                                   ON ACS.agent_group_id = A.customized_setting_group_id
                                       AND setting_key = ?
                                       AND ACS.`value` = 1
                              JOIN summary_bet_records_team AS B
                                   ON B.member_id = MA.member_id
                     WHERE MA.agent_level = ?
                       AND B.summary_time >= ?
                       AND B.summary_time < ?
                       AND B.vendor_id NOT IN ({$dailyVendorIds})
                     GROUP BY MA.member_id, B.vendor_id, B.game_id
                 ) AS D
            GROUP BY D.member_id, D.vendor_id, D.game_id
            SQL,
            [
                $start,
                AgentCustomizedSettingKeyEnum::AGENT_MODE_SETTING, $settlementDate, $level, $start, $end,
                AgentCustomizedSettingKeyEnum::AGENT_MODE_SETTING, $level + 1, $start, $end,
            ],
        );
    }

    /**
     * 918/mega888/pussy888（整日格）：同上兩段結構，但讀寫 summary_time = DATE(start +8h)。
     */
    private function insertDailyLevel(int $level, string $summaryTime, string $settlementDate, string $dailyVendorIds): void
    {
        DB::insert(
            <<<SQL
            INSERT INTO summary_bet_records_team(
                summary_time, member_id, vendor_id, game_id,
                bet, bet_direct, bet_team,
                win_lose, win_lose_direct, win_lose_team, `admin`
            )
            SELECT ?
                 , D.member_id
                 , D.vendor_id
                 , D.game_id
                 , SUM(D.bet) AS bet
                 , SUM(D.bet_direct) AS bet_direct
                 , SUM(D.bet_team) AS bet_team
                 , SUM(D.win_lose) AS win_lose
                 , SUM(D.win_lose_direct) AS win_lose_direct
                 , SUM(D.win_lose_team) AS win_lose_team
                 , 0 AS `admin`
            FROM (
                     SELECT MA.member_id
                          , B.vendor_id
                          , B.game_id
                          , CASE MUC.`status` WHEN 1 THEN 0 ELSE SUM(B.bet) END AS bet
                          , SUM(B.win_lose) AS win_lose
                          , 0 AS bet_direct
                          , 0 AS win_lose_direct
                          , 0 AS bet_team
                          , 0 AS win_lose_team
                     FROM member_agent AS MA
                              JOIN agents AS A
                                   ON A.id = MA.parent_id
                              JOIN agent_customized_settings AS ACS
                                   ON ACS.agent_group_id = A.customized_setting_group_id
                                       AND setting_key = ?
                                       AND ACS.`value` = 1
                              JOIN summary_bet_records AS B
                                   ON B.member_id = MA.member_id
                              LEFT JOIN member_unlimit_cancels AS MUC
                                        ON MUC.member_id = MA.member_id
                                            AND `date` = ?
                     WHERE MA.agent_level = ?
                       AND B.summary_time = ?
                       AND B.vendor_id IN ({$dailyVendorIds})
                     GROUP BY MA.member_id, B.vendor_id, B.game_id
                     UNION ALL
                     SELECT MA.topper_member_id AS member_id
                          , B.vendor_id
                          , B.game_id
                          , 0 AS bet
                          , 0 AS win
                          , SUM(B.bet) AS bet_direct
                          , SUM(B.win_lose) AS win_lose_direct
                          , SUM(B.bet_direct + B.bet_team) AS bet_team
                          , SUM(B.win_lose_direct + B.win_lose_team) AS win_lose_team
                     FROM member_agent AS MA
                              JOIN agents AS A
                                   ON A.id = MA.parent_id
                              JOIN agent_customized_settings AS ACS
                                   ON ACS.agent_group_id = A.customized_setting_group_id
                                       AND setting_key = ?
                                       AND ACS.`value` = 1
                              JOIN summary_bet_records_team AS B
                                   ON B.member_id = MA.member_id
                     WHERE MA.agent_level = ?
                       AND B.vendor_id IN ({$dailyVendorIds})
                       AND B.summary_time = ?
                     GROUP BY MA.member_id, B.vendor_id, B.game_id
                 ) AS D
            GROUP BY D.member_id, D.vendor_id, D.game_id
            SQL,
            [
                $summaryTime,
                AgentCustomizedSettingKeyEnum::AGENT_MODE_SETTING, $settlementDate, $level, $summaryTime,
                AgentCustomizedSettingKeyEnum::AGENT_MODE_SETTING, $level + 1, $summaryTime,
            ],
        );
    }

    /**
     * COM_Update_Control_Table(10006, 'summary_bet_records_team', 0, ...) 的移植：
     * 固定列 upsert 最後統計窗口 ＋ 追加一筆執行紀錄（維持雙軌期間觀測面一致）。
     */
    private function writeControlTable(string $start, string $end, string $execStart, string $execEnd): void
    {
        DB::statement(
            <<<'SQL'
            INSERT INTO summary_control_tables(
                id, vendor_id, vendor_code, stat_start_time, stat_end_time, exec_start_time, exec_end_time
            )
            VALUES(?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE stat_start_time = ?, stat_end_time = ?, exec_start_time = ?, exec_end_time = ?
            SQL,
            [
                self::CONTROL_TABLE_ID, self::CONTROL_TABLE_ID, 'summary_bet_records_team', $start, $end, $execStart, $execEnd,
                $start, $end, $execStart, $execEnd,
            ],
        );

        DB::insert(
            <<<'SQL'
            INSERT INTO summary_control_logs(
                vendor_id, stat_start_time, stat_end_time, exec_start_time, exec_end_time, params
            )
            VALUES(?, ?, ?, ?, ?, ?)
            SQL,
            [self::CONTROL_TABLE_ID, $start, $end, $execStart, $execEnd, $start],
        );
    }

    /**
     * 按台北日整日刪除重建的遊戲商（918/mega888/pussy888）；
     * 純 enum 整數值 inline 進 SQL，非外部輸入。
     */
    private function dailyRebuildVendorIds(): string
    {
        return implode(', ', [
            VendorIdEnum::SCR->value,
            VendorIdEnum::MEGA888->value,
            VendorIdEnum::PUSSY->value,
        ]);
    }
}
