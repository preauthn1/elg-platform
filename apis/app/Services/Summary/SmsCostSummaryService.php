<?php

declare(strict_types=1);

namespace App\Services\Summary;

use Illuminate\Support\Facades\DB;

/**
 * 簡訊成本彙總 summary_event_records／summary_event_record_agents（event 4）的唯一寫入者 target/tickets#4424。
 *
 * 取代 SP_summary_event_record_by_sms（由 MySQL EVENT Event_Summary_event_reocrd_by_sms 每小時呼叫）；
 * 結構比照同目錄的 VipRewardSummaryService（target/tickets#4420）與 PaymentCostSummaryService（target/tickets#4369），
 * 排程與回補共用本類，口徑只有一份。
 *
 * 規則（必須遵守）：
 * - **直接加總 `sms_send_records.cost`，禁止在此重算費率。** 成本已於發送當下凍結（見
 *   `SmsGatewayService::sendSms()`）。原 SP 不凍結、改用「代理的通道組＋簡訊商」反推單價，
 *   而同一家簡訊商在同一通道組可以有多國費率（TPower 通道組 675 的通道 11 有越南 0.4595 與
 *   印尼 0.3061 兩列），反推時兩列都命中，一則被算成 0.7656。凍結之後這個問題連同那三個
 *   join（`members`／`agents`／`sms_gateway_infos`）一起消失。
 * - **會員彙總與代理彙總的刪除與重建一律在同一個交易內**，禁止拆成兩段裸跑。原 SP 正是分兩段：
 *   失敗時 `EXIT HANDLER` 吞掉例外不回滾，而視窗每小時只前進 1 小時，失敗那班若已是最後一班
 *   涵蓋某格的，那一格就永久空掉。TPower 自 2026-01-07 起因此漏 130 個小時、VWorld 173 個。
 * - **只取 `member_id > 0`**，這是刻意的：TPower 實測九月份 82% 的成功發送是代理發的簡訊
 *   （`member_id = 0`、`agent_id` 才有值），原 SP 的 `JOIN members` 把它們整批排除，因為這張
 *   彙總表以 `member_id` 為鍵、代理簡訊沒有會員可歸屬。拿掉 join 後若不補這個條件，會灌進
 *   大量 `member_id = 0` 的列。代理簡訊的成本要不要進報表是另一個題目，不在本類處理。
 * - **只取 `status = 1`（發送成功）**，與原 SP 相同；失敗的簡訊仍會寫 `cost`，但不計入成本。
 * - 每次對整段 UTC 區間「先刪後重建」；主鍵 (summary_time, member_id, event_id) 因此不會撞，重跑安全。
 * - 分桶以 `sms_send_records.created_at` 的 UTC 整點為界，與原 SP 相同。
 * - 不寫 `summary_control_tables`／`summary_control_logs`（那是 SP 排程自己的記帳，看板與 newsr11 都不讀）。
 */
readonly class SmsCostSummaryService
{
    /** summary_event_records.event_id：簡訊成本（沿用原 SP 的固定值 4） */
    public const int EVENT_ID = 4;

    /** sms_send_records.status：發送成功（同表欄位註解 0 未發送／1 發送成功／2 發送失敗） */
    private const int SEND_SUCCESS = 1;

    /**
     * 區間內目前的會員彙總列數（乾跑用）。
     */
    public function countMembers(string $startUtc, string $endUtc): int
    {
        return DB::table('summary_event_records')
            ->where('event_id', self::EVENT_ID)
            ->where('summary_time', '>=', $startUtc)
            ->where('summary_time', '<', $endUtc)
            ->count();
    }

    /**
     * 區間內還沒回補成本的成功發送筆數；大於 0 代表這段的彙總會少算（乾跑與驗收用）。
     */
    public function countUnfrozen(string $startUtc, string $endUtc): int
    {
        return DB::table('sms_send_records')
            ->where('status', self::SEND_SUCCESS)
            ->where('member_id', '>', 0)
            ->where('sms_gateway_info_id', 0)
            ->where('created_at', '>=', $startUtc)
            ->where('created_at', '<', $endUtc)
            ->count();
    }

    /**
     * 刪除區間內的舊列 → 重建會員彙總 → 由會員彙總重建代理彙總，全部在一個交易裡。
     *
     * 交易是本次改造的重點：任何一步失敗就整筆回滾，區間維持上一次成功的內容，
     * 不會留下「刪掉了卻沒補回來」的空格。
     *
     * @return array{members: int, agents: int} 寫入列數
     */
    public function rebuild(string $startUtc, string $endUtc): array
    {
        return DB::transaction(function () use ($startUtc, $endUtc): array {
            foreach (['summary_event_records', 'summary_event_record_agents'] as $table) {
                DB::table($table)
                    ->where('event_id', self::EVENT_ID)
                    ->where('summary_time', '>=', $startUtc)
                    ->where('summary_time', '<', $endUtc)
                    ->delete();
            }

            // 成本已凍結在發送紀錄上，這裡只做分桶加總，沒有任何 join
            $members = DB::affectingStatement(
                "INSERT INTO summary_event_records (summary_time, member_id, event_id, `point`)
                 SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS summary_time, member_id, ?, SUM(cost)
                   FROM sms_send_records
                  WHERE `status` = ? AND member_id > 0 AND created_at >= ? AND created_at < ?
                  GROUP BY summary_time, member_id
                 HAVING SUM(cost) > 0",
                [self::EVENT_ID, self::SEND_SUCCESS, $startUtc, $endUtc],
            );

            // 代理彙總由會員彙總派生，members／agents 這兩個 join 是它的定義本身
            $agents = DB::affectingStatement(
                'INSERT INTO summary_event_record_agents (summary_time, agent_id, event_id, `point`)
                 SELECT r.summary_time, a.id, r.event_id, SUM(r.`point`)
                   FROM summary_event_records r
                   JOIN members m ON m.id = r.member_id
                   JOIN agents a ON a.id = m.parent
                  WHERE r.event_id = ? AND r.summary_time >= ? AND r.summary_time < ?
                  GROUP BY r.summary_time, a.id, r.event_id',
                [self::EVENT_ID, $startUtc, $endUtc],
            );

            return ['members' => $members, 'agents' => $agents];
        });
    }
}
