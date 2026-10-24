<?php

declare(strict_types=1);

namespace App\Services\Agent\GgrSettlement;

use App\Helpers\Common;
use App\Models\Agent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 獎金歸集：SR15 代理對帳報表「彩金與活動」（jackpot_event）−「回收金額」的
 * 等價實作（2026-09-01 決議①②⑥：改用 SR15 口徑、回收同源扣回、時間認列
 * 完全沿用 SR15 既有邏輯）。出處 API_Agent ReportController sr15_* 子查詢。
 *
 * 五項組成（模式 5 代理的無限代／三層代返水恆 0，不查）：
 * 1. point：transaction_logs type ∈ REPORT_EVENT_TYPES、created_at 認列、
 *    傳入時間 −8hour（UTC 資料庫、GMT+8 業務窗）、不濾 status（SR15 現況）
 * 2. wallet_point：wallet_transaction_logs type 7（活動派彩）、同窗、不濾 status
 * 3. jackpot：SBR jackpot ＋ rolling_chip（泥碼 CLEAR_ROLLING_CHIP_TIME 截止——
 *    SR15 現況為寫死值，env 分支是死碼，見常數註解）、summary_time −8hour
 * 4. lottery：summary_event_record_agents event_id 6/7（報表端綁 -6/-7 哨兵旗標，
 *    引擎固定計入；QA 對帳需 SR15 全選遊戲商且總代設定 5010=1）
 * 5. recycle：event_v2_recycles −Σrecycle_amount、created_at 認列、不濾 status、
 *    **時間窗不減 8 小時**（SR15 現況既有不一致，照抄）。SR15 報表端此欄為
 *    查詢者層級一次計算的常數列（leftJoin 未實際過濾代理），引擎改為
 *    per-agent 直屬會員歸集——對帳時報表該欄與引擎值可能不同，屬報表既有行為
 *
 * 獎金基數 = max(0, jackpot_event − recycle)（決議③，多出的回收當期截斷）。
 */
class BonusCollector
{
    /**
     * SR15 point 白名單（API_Agent TransactionEnum::REPORT_EVENT_TYPES）。
     * 紅包(6-9)／活動錢包v2派獎(71)／VIP(32) 不在名單內＝決議①接受的缺口。
     *
     * @var array<int, int>
     */
    private const array REPORT_EVENT_TYPES = [10, 13, 15, 19, 20, 21, 24, 25, 26, 30, 31, 101, 102, 103, 105, 107, 109];

    /** wallet_transaction_logs type：活動派彩（WalletTransactionEnum::GAME_WALLET_EVENT_REWARD） */
    private const int WALLET_TYPE_EVENT_REWARD = 7;

    /**
     * 泥碼（rolling_chip）計入截止時間：SR15 現況為 ReportController.php:2492
     * 寫死的值（:2481 的 env CLEAR_ROLLING_CHIP_TIME 分支從未被 select 使用，
     * 是死碼）。沿用寫死值才與報表對得上帳（決議⑥）。
     */
    private const string CLEAR_ROLLING_CHIP_TIME = '2025-07-31 16:00:00';

    /** summary_event_record_agents 的抽獎類 event_id（SR15 併入 jackpot 的 lottery） */
    private const array LOTTERY_EVENT_IDS = [6, 7];

    /**
     * @param  array<int, int>  $memberIds  直屬下線會員 id
     * @return array{point: string, wallet_point: string, jackpot: string, lottery: string, recycle: string, base: string}
     */
    public function collect(
        Agent $agent,
        array $memberIds,
        Carbon $utcStart,
        Carbon $utcEnd,
        string $businessStart,
        string $businessEnd,
    ): array {
        $point = $this->sumPoint($memberIds, $utcStart, $utcEnd);
        $walletPoint = $this->sumWalletPoint($memberIds, $utcStart, $utcEnd);
        $jackpot = $this->sumJackpot($memberIds, $utcStart, $utcEnd);
        $lottery = $this->sumLottery($agent, $utcStart, $utcEnd);
        $recycle = $this->sumRecycle($memberIds, $businessStart, $businessEnd);

        $jackpotEvent = Common::numAdd(Common::numAdd($point, $walletPoint), Common::numAdd($jackpot, $lottery));
        $base = Common::numSub($jackpotEvent, $recycle);

        // 決議③：回收 > 彩金時基數以 0 為下限，不出現負的獎金費用
        if (Common::numCompare($base, '0') < 0) {
            $base = '0';
        }

        return [
            'point' => $point,
            'wallet_point' => $walletPoint,
            'jackpot' => $jackpot,
            'lottery' => $lottery,
            'recycle' => $recycle,
            'base' => $base,
        ];
    }

    /** @param array<int, int> $memberIds */
    private function sumPoint(array $memberIds, Carbon $utcStart, Carbon $utcEnd): string
    {
        if ($memberIds === []) {
            return '0';
        }

        $sum = DB::table('transaction_logs')
            ->whereIn('member_id', $memberIds)
            ->where('created_at', '>=', $utcStart)
            ->where('created_at', '<', $utcEnd)
            ->whereIn('type', self::REPORT_EVENT_TYPES)
            ->selectRaw('IFNULL(SUM(TRUNCATE(point, 4)), 0) as total')
            ->value('total');

        return (string) $sum;
    }

    /** @param array<int, int> $memberIds */
    private function sumWalletPoint(array $memberIds, Carbon $utcStart, Carbon $utcEnd): string
    {
        if ($memberIds === []) {
            return '0';
        }

        $sum = DB::table('wallet_transaction_logs')
            ->whereIn('member_id', $memberIds)
            ->where('created_at', '>=', $utcStart)
            ->where('created_at', '<', $utcEnd)
            ->where('type', self::WALLET_TYPE_EVENT_REWARD)
            ->selectRaw('IFNULL(SUM(TRUNCATE(point, 4)), 0) as total')
            ->value('total');

        return (string) $sum;
    }

    /** @param array<int, int> $memberIds */
    private function sumJackpot(array $memberIds, Carbon $utcStart, Carbon $utcEnd): string
    {
        if ($memberIds === []) {
            return '0';
        }

        $sum = DB::table('summary_bet_records')
            ->whereIn('member_id', $memberIds)
            ->where('summary_time', '>=', $utcStart)
            ->where('summary_time', '<', $utcEnd)
            ->selectRaw(
                'IFNULL(SUM(jackpot + (CASE WHEN summary_time >= ? THEN 0 ELSE rolling_chip END)), 0) as total',
                [self::CLEAR_ROLLING_CHIP_TIME],
            )
            ->value('total');

        return (string) $sum;
    }

    private function sumLottery(Agent $agent, Carbon $utcStart, Carbon $utcEnd): string
    {
        $sum = DB::table('summary_event_record_agents')
            ->where('agent_id', $agent->id)
            ->where('summary_time', '>=', $utcStart)
            ->where('summary_time', '<', $utcEnd)
            ->whereIn('event_id', self::LOTTERY_EVENT_IDS)
            ->selectRaw('IFNULL(SUM(TRUNCATE(point, 4)), 0) as total')
            ->value('total');

        return (string) $sum;
    }

    /**
     * 回收金額（正值回傳，呼叫端由基數扣除）。
     * 時間窗照 SR15 現況以 GMT+8 業務時間字串直接比 UTC created_at（不減 8 小時）。
     *
     * @param  array<int, int>  $memberIds
     */
    private function sumRecycle(array $memberIds, string $businessStart, string $businessEnd): string
    {
        if ($memberIds === []) {
            return '0';
        }

        $sum = DB::table('event_v2_recycles')
            ->join('event_v2_user_joins', 'event_v2_user_joins.id', '=', 'event_v2_recycles.event_v2_user_join_id')
            ->whereIn('event_v2_user_joins.member_id', $memberIds)
            ->where('event_v2_recycles.created_at', '>=', $businessStart)
            ->where('event_v2_recycles.created_at', '<', $businessEnd)
            ->selectRaw('IFNULL(SUM(event_v2_recycles.recycle_amount), 0) as total')
            ->value('total');

        return (string) $sum;
    }
}
