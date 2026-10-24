<?php

declare(strict_types=1);

namespace App\Services\Summary;

use App\Enums\Transaction\TransactionEnum;
use Illuminate\Support\Facades\DB;

/**
 * VIP 獎勵彙總 summary_event_records／summary_event_record_agents（event 9）的唯一寫入者 target/tickets#4420。
 *
 * 取代 SP_summary_event_record_by_vip（由 MySQL EVENT Event_Summary_event_reocrd_by_vip 每小時呼叫）；
 * 結構比照同目錄的 PaymentCostSummaryService（target/tickets#4369），排程與回補共用本類，口徑只有一份。
 *
 * 規則（必須遵守）：
 * - **會員彙總與代理彙總的刪除與重建一律在同一個交易內**，禁止拆成兩段裸跑。原 SP 正是分兩段：
 *   2026-09-22 08:00 那班刪掉代理列之後失敗，`EXIT HANDLER` 吞掉例外不回滾，而視窗每小時只前進 1 小時、
 *   該班已是最後一班涵蓋 05:00 的，於是那一格永久空掉。TPower 7/1 以來共 46 天帶有這種缺漏小時。
 * - **一律全站重建，不接受代理線參數**。VIP 彙總沒有分線的概念（原 SP 也沒有），加上
 *   `JOIN agents ... hierarchy_path LIKE` 只是在每句 DELETE／INSERT 多兩個 join 卻濾不掉任何東西，
 *   而且會把「`members.parent` 指向已不存在的 agent」那批會員（TPower 有 60 萬個）的 VIP 列排除掉——
 *   原 SP 會算進去。PaymentCostSummaryService 有 `--agent` 是因為 Yes2Win 另有按線跑的 `_W` SP，不是通例。
 * - 來源為 `transaction_logs` 的 VIP 獎勵（type 32），**與原 SP 一樣不濾 status**，這是刻意的：
 *   TPower 自 2026-07-01 起 type 32 沒有任何一筆非 1000，加 `status = 1000` 買不到東西，卻會讓優化器改選
 *   `index_merge intersect(idx_transaction_logs_type, idx_transaction_logs_bak_status)`——拿兩個低選擇性索引
 *   做交集、完全不用 `created_at`，實測單日回補 10 秒還跑不完；拿掉後回到 `ref` 掃 type（9 萬列）秒級完成。
 *   真的需要濾 status 時，先補 `(type, created_at)` 複合索引再說。
 * - 每次對整段 UTC 區間「先刪後重建」；主鍵 (summary_time, member_id, event_id) 因此不會撞，重跑安全。
 * - 分桶以 `transaction_logs.created_at` 的 UTC 整點為界，與原 SP 相同。
 * - 不寫 `summary_control_tables`／`summary_control_logs`（那是 SP 排程自己的記帳，看板與 newsr11 都不讀；
 *   TPower 根本沒有 `vendor_id = 10007` 那一列，原 SP 結尾的 UPDATE 一直是影響 0 列的空操作）。
 */
readonly class VipRewardSummaryService
{
    /** summary_event_records.event_id：VIP 獎勵（沿用原 SP 的固定值 9） */
    public const int EVENT_ID = 9;

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
     * 刪除區間內的舊列 → 重建會員彙總 → 由會員彙總重建代理彙總，全部在一個交易裡。
     *
     * 交易是本次改造的重點：任何一步失敗就整筆回滾，區間維持上一次成功的內容，
     * 不會留下「刪掉了卻沒補回來」的空格。
     *
     * ponytail: 整段區間一個交易；回補以日為單位分段呼叫即可（見 SummarizeVipReward 的日期模式），
     * 真的要一次吃下年級區間再改逐日分段。
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

            $members = DB::affectingStatement(
                "INSERT INTO summary_event_records (summary_time, member_id, event_id, `point`)
                 SELECT DATE_FORMAT(t.created_at, '%Y-%m-%d %H:00:00') AS summary_time, t.member_id, ?, SUM(t.`point`)
                   FROM transaction_logs t
                  WHERE t.`type` = ? AND t.created_at >= ? AND t.created_at < ?
                  GROUP BY summary_time, t.member_id",
                [self::EVENT_ID, TransactionEnum::VIP_REWARD->value, $startUtc, $endUtc],
            );

            // 代理彙總是由會員彙總派生，members／agents 這兩個 join 是它的定義本身，不是範疇限制
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
