<?php

declare(strict_types=1);

namespace App\Services\Summary;

use App\Enums\OrderStatusEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use Illuminate\Support\Facades\DB;

/**
 * 支付成本彙總 summary_event_records／summary_event_record_agents（event 3 代收、8 代付）的唯一寫入者 target/tickets#4369。
 *
 * 取代 SP_summary_event_record_by_payment（每小時 EVENT）與 SP_summary_event_record_by_payment_W（每日 EVENT，只有 Yes2Win 有）：
 * 排程（summary:payment-cost）與重算（order:recalc-payment-cost-summary）共用本類，口徑只有一份。
 *
 * 規則（必須遵守）：
 * - 成本一律直接加總訂單上凍結的 payment_cost（AgentCashFlow::paymentCostFor()：三方代收於回調成功、
 *   自助上分於建單、代付於派款成功時寫入），禁止在此重算費率。
 * - 每個 event 對整段 UTC 區間、指定代理線（hierarchy_path 前綴）「先刪後重建」，會員彙總與代理彙總同一交易；
 *   主鍵 (summary_time, member_id, event_id) 因此不會撞，重跑安全。
 * - 分桶：代收依 orders.pay_time（status=3）；代付（status 24／4）依 withdrawal_orders.payment_time；小時桶以 UTC 整點為界。
 * - 不寫 summary_control_tables／summary_control_logs（那是 SP 排程的記帳，看板不讀）。
 *
 * @knowledge rules/payment-cost-summary
 */
readonly class PaymentCostSummaryService
{
    public const int DEPOSIT_EVENT = 3;

    public const int WITHDRAWAL_EVENT = 8;

    /** event_id => 顯示名稱（命令輸出用） */
    public const array EVENT_LABELS = [
        self::DEPOSIT_EVENT => '代收',
        self::WITHDRAWAL_EVENT => '代付',
    ];

    /**
     * 代理的 hierarchy_path 前綴（LIKE 用，Admin 為 /1% 涵蓋全站）；找不到代理回 null。
     */
    public function agentPathPrefix(string $username): ?string
    {
        $path = DB::table('agents')->where('username', $username)->value('hierarchy_path');

        return $path === null ? null : rtrim((string) $path, '/') . '%';
    }

    /**
     * 區間內該代理線目前的會員彙總列數（乾跑用）。
     */
    public function countMembers(int $eventId, string $startUtc, string $endUtc, string $agentPath): int
    {
        return DB::table('summary_event_records as r')
            ->join('members as m', 'm.id', '=', 'r.member_id')
            ->join('agents as a', 'a.id', '=', 'm.parent')
            ->where('r.event_id', $eventId)
            ->where('r.summary_time', '>=', $startUtc)
            ->where('r.summary_time', '<', $endUtc)
            ->where('a.hierarchy_path', 'like', $agentPath)
            ->count();
    }

    /**
     * 刪除區間內該代理線的舊列 → 重建會員彙總 → 由會員彙總重建代理彙總，一個交易。
     *
     * ponytail: 整段區間一個交易；月級重算（TPower 兩個月 24 萬列）34 秒可接受，年級再改逐日分段。
     *
     * @return array{members: int, agents: int} 寫入列數
     */
    public function rebuild(int $eventId, string $startUtc, string $endUtc, string $agentPath): array
    {
        return DB::transaction(function () use ($eventId, $startUtc, $endUtc, $agentPath): array {
            DB::delete(
                'DELETE r FROM summary_event_records r
                    JOIN members m ON m.id = r.member_id
                    JOIN agents a ON a.id = m.parent
                  WHERE r.event_id = ? AND r.summary_time >= ? AND r.summary_time < ? AND a.hierarchy_path LIKE ?',
                [$eventId, $startUtc, $endUtc, $agentPath],
            );
            DB::delete(
                'DELETE ra FROM summary_event_record_agents ra
                    JOIN agents a ON a.id = ra.agent_id
                  WHERE ra.event_id = ? AND ra.summary_time >= ? AND ra.summary_time < ? AND a.hierarchy_path LIKE ?',
                [$eventId, $startUtc, $endUtc, $agentPath],
            );

            // 代收：已付款訂單依 pay_time；代付：已出款訂單（三方成功 24／人工出款 4）依 payment_time
            // （24 的 payment_time 自 target/tickets#3967 起由回調成功／人工定案寫入、歷史單已回填，
            //  不再像舊 SP 另走 withdrawal_payout_records.callback_time）
            $members = $eventId === self::DEPOSIT_EVENT
                ? $this->insertMembers($eventId, 'orders', 'pay_time', [OrderStatusEnum::DEPOSIT_FINISHED->value], $startUtc, $endUtc, $agentPath)
                : $this->insertMembers($eventId, 'withdrawal_orders', 'payment_time', [WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, WithdrawalOrderStatusEnum::TRANSFERRED->value], $startUtc, $endUtc, $agentPath);

            $agents = DB::affectingStatement(
                'INSERT INTO summary_event_record_agents (summary_time, agent_id, event_id, `point`)
                 SELECT r.summary_time, a.id, r.event_id, SUM(r.`point`)
                   FROM summary_event_records r
                   JOIN members m ON m.id = r.member_id
                   JOIN agents a ON a.id = m.parent
                  WHERE r.event_id = ? AND r.summary_time >= ? AND r.summary_time < ? AND a.hierarchy_path LIKE ?
                  GROUP BY r.summary_time, a.id, r.event_id',
                [$eventId, $startUtc, $endUtc, $agentPath],
            );

            return ['members' => $members, 'agents' => $agents];
        });
    }

    /**
     * 會員小時彙總：訂單依 $timeColumn 分 UTC 整點桶，直接加總凍結的 payment_cost（4 位，SUM 進 decimal(20,6) 不失真）。
     * 範圍條件已排除 $timeColumn 為 NULL 的列，不會產生 NULL 桶撞主鍵。
     *
     * @param  list<int>  $statuses
     */
    private function insertMembers(int $eventId, string $table, string $timeColumn, array $statuses, string $startUtc, string $endUtc, string $agentPath): int
    {
        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));

        return DB::affectingStatement(
            "INSERT INTO summary_event_records (summary_time, member_id, event_id, `point`)
             SELECT DATE_FORMAT(o.`{$timeColumn}`, '%Y-%m-%d %H:00:00') AS summary_time, m.id, ?, SUM(o.payment_cost)
               FROM `{$table}` o
               JOIN members m ON m.id = o.member_id
               JOIN agents a ON a.id = m.parent AND a.hierarchy_path LIKE ?
              WHERE o.status IN ({$statusPlaceholders}) AND o.`{$timeColumn}` >= ? AND o.`{$timeColumn}` < ?
              GROUP BY summary_time, m.id
             HAVING SUM(o.payment_cost) > 0",
            [$eventId, $agentPath, ...$statuses, $startUtc, $endUtc],
        );
    }
}
