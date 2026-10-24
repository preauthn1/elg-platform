<?php

declare(strict_types=1);

namespace App\Console\Commands\SummaryBetRecord\SP;

use Illuminate\Support\Facades\DB;

/**
 * 滾碼（禮碼）小時彙總（summary_rolling_chip_records）——
 * 同名 SP（database/procedures/SP_summary_rolling_chip_record.sql）的忠實 PHP 移植
 *
 * ⚠️ 雙軌等值約束（target/tickets#4257）：舊系統 RD-Studio-01 每小時仍呼叫原 SP 對
 * 同一格刪除重建，本移植的產出必須與 SP 逐列一致（見等值測試
 * SummaryProcedurePortEquivalenceTest）；雙軌收斂前禁止在此「順手優化」。
 * 與 SP 的刻意差異僅：錯誤通知改由 PHP 例外上拋（呼叫端 BotNotify）、
 * NULL 參數的「預設前一小時」分支未移植（呼叫端一律傳明確 bucket）。
 *
 * 五個來源以 UNION（去重）彙整（對齊 SP 版本註記 2023-06-08）：
 * 三層代獎勵、活動獎勵、投注消耗禮碼、VIP 獎勵、禮碼發放紀錄；
 * 收尾鏈式執行獎勵彙總（對齊 SP 尾端的 CALL SP_summary_event_record_by_rolling_chip）。
 */
class SP_summary_rolling_chip_record
{
    public function __construct(
        private readonly SP_summary_event_record_by_rolling_chip $eventWriter = new SP_summary_event_record_by_rolling_chip,
    ) {}

    public function write(string $startTime): void
    {
        $start = $startTime;
        $end = date('Y-m-d H:i:s', strtotime($start . ' +1 hour'));

        DB::delete('DELETE FROM summary_rolling_chip_records WHERE summary_time = ?', [$start]);

        DB::insert(
            <<<'SQL'
            INSERT INTO summary_rolling_chip_records(summary_time, member_id, pay_rolling_chip, use_rolling_chip)
            SELECT ?,
                   member_id,
                   SUM(D.pay_rolling_chip) AS pay_rolling_chip,
                   SUM(D.use_rolling_chip) AS use_rolling_chip
            FROM
                (
                    # 三層代獎勵
                    SELECT member_id AS member_id,
                           SUM(rebate + down_rebate + down2_rebate) AS pay_rolling_chip,
                           0 AS use_rolling_chip
                    FROM three_hierarchy_bonus_report
                    WHERE created_at >= ? AND created_at < ? AND reward_type = 2 AND agent_id = 0
                    GROUP BY member_id
                    UNION # 活動獎勵
                    SELECT pm.member_id AS member_id,
                           SUM(`point`) AS pay_rolling_chip,
                           0 AS use_rolling_chip
                    FROM prize_members AS pm
                             JOIN prize_contents AS pc ON pc.id = pm.content_id AND point_type = 2
                    WHERE pm.updated_at >= ? AND pm.updated_at < ? AND pm.`status` = 1
                    GROUP BY pm.member_id
                    UNION  # 投注遊戲消耗禮碼
                    SELECT member_id AS member_id,
                           0 AS pay_rolling_chip,
                           SUM(rolling_chip) AS use_rolling_chip
                    FROM summary_bet_records
                    WHERE summary_time >= ? AND summary_time < ?
                    GROUP BY member_id
                    HAVING SUM(rolling_chip) > 0
                    UNION  # VIP獎勵
                    SELECT member_id  AS member_id,
                           SUM(`point`) AS pay_rolling_chip,
                           0 AS use_rolling_chip
                    FROM member_vip_prizes
                    WHERE updated_at >= ? AND updated_at < ? AND `status` = 1 AND point_type = 2
                    GROUP BY member_id
                    UNION  # 註冊/綁定手機獎勵 & 代理後台贈送禮碼 & 優惠碼
                    SELECT member_id  AS member_id,
                           SUM(amount) AS pay_rolling_chip,
                           0 AS use_rolling_chip
                    FROM rolling_chip_logs
                    WHERE created_at >= ? AND created_at < ? AND `type` in (5, 11, 23)
                    GROUP BY member_id
                ) AS D
            GROUP BY D.member_id
            SQL,
            [$start, $start, $end, $start, $end, $start, $end, $start, $end, $start, $end],
        );

        $this->eventWriter->write($start, $end);
    }
}
