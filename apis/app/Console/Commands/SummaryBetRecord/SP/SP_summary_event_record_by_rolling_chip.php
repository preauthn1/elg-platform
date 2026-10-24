<?php

declare(strict_types=1);

namespace App\Console\Commands\SummaryBetRecord\SP;

use Illuminate\Support\Facades\DB;

/**
 * 禮碼發放的獎勵彙總（summary_event_records / summary_event_record_agents）——
 * 同名 SP（database/procedures/SP_summary_event_record_by_rolling_chip.sql）的忠實 PHP 移植
 *
 * ⚠️ 雙軌等值約束（target/tickets#4257）：舊系統 RD-Studio-01 的滾碼 SP 仍鏈式呼叫
 * 原 SP，本移植的產出必須與 SP 逐列一致（見等值測試
 * SummaryProcedurePortEquivalenceTest）；雙軌收斂前禁止在此「順手優化」。
 * 與 SP 的刻意差異僅：錯誤通知改由 PHP 例外上拋（呼叫端 BotNotify）、
 * NULL 參數的預設窗口分支未移植（呼叫端一律傳明確窗口）。
 *
 * 邏輯：輸入窗口截斷到整點後，將窗內 pay_rolling_chip > 0 的滾碼彙總列
 * 轉入 summary_event_records（event_id 固定 6 = V-world 獎勵），再依會員
 * 所屬代理彙總進 summary_event_record_agents；兩表窗內舊列先刪除重建。
 */
class SP_summary_event_record_by_rolling_chip
{
    /** summary_event_records.event_id：V-world 獎勵（沿用 SP 的固定值 6） */
    private const int EVENT_ID = 6;

    public function write(string $startTime, string $endTime): void
    {
        // SP 將輸入截斷到整點：DATE_ADD(DATE(t), INTERVAL HOUR(t) HOUR)
        $start = date('Y-m-d H:00:00', strtotime($startTime));
        $end = date('Y-m-d H:00:00', strtotime($endTime));

        DB::delete(
            'DELETE FROM summary_event_records WHERE event_id = ? AND summary_time >= ? AND summary_time < ?',
            [self::EVENT_ID, $start, $end],
        );

        DB::insert(
            <<<'SQL'
            INSERT INTO summary_event_records(summary_time, member_id, event_id, `point`)
            SELECT summary_time, member_id, ?, pay_rolling_chip
            FROM summary_rolling_chip_records
            WHERE summary_time >= ? AND summary_time < ? AND pay_rolling_chip > 0
            SQL,
            [self::EVENT_ID, $start, $end],
        );

        DB::delete(
            'DELETE FROM summary_event_record_agents WHERE event_id = ? AND summary_time >= ? AND summary_time < ?',
            [self::EVENT_ID, $start, $end],
        );

        DB::insert(
            <<<'SQL'
            INSERT INTO summary_event_record_agents(
                summary_time, agent_id, event_id, `point`
            )
            SELECT y.summary_time, y.agent_id, y.event_id, y.`point`
            FROM (
                SELECT r.summary_time
                    , a.id AS agent_id
                    , r.event_id
                    , SUM(r.`point`) AS `point`
                FROM summary_event_records AS r
                JOIN members AS m ON m.id = r.member_id
                JOIN agents AS a ON a.id = m.parent
                WHERE summary_time >= ?
                AND summary_time < ?
                AND r.event_id = ?
                GROUP BY r.summary_time, a.id, r.event_id
            ) AS y
            SQL,
            [$start, $end, self::EVENT_ID],
        );
    }
}
