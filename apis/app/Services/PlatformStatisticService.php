<?php

declare(strict_types=1);

namespace App\Services;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Services\Agent\AgentDashboardService;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 平台累計統計（target/tickets#4145）：官方形象站首頁「平台核心数据」的四個數字。
 *
 * 設計要點：
 *
 * - **全歷史累計，不吃時間區間**。既有的 `AgentDashboardService` 全部是區間查詢
 *   （`start_time`/`end_time` 必填），`member-team/*` 是單一會員範圍，兩者都當不了累計值，
 *   故另開這一支；口徑相關的常數一律呼叫 `AgentDashboardService` 的 public accessor，不複製清單。
 * - **範疇是品牌代理線**，不是整個 DB。一個 DB 裝多個白標，以 `domain` 解析到品牌根代理後，
 *   用 `hierarchy_path` 前綴收斂（做法與 `AgentDashboardService::scopeSubquery()` 同一規則）。
 * - **快取 1 小時**。第 3、4 項是全歷史 SUM，絕不能每次請求都跑；`safeRemember` 同時擋掉擊穿。
 *   `transaction_logs` 有 `(member_id, type, status, created_at)` 複合索引，以 members 驅動
 *   逐會員走索引，冷快取仍在可接受範圍。
 */
class PlatformStatisticService
{
    /** 四個數字一起算、一起快取（前台是同一張版面，沒有只要其中一項的情境） */
    public function overview(Agent $brandAgent): array
    {
        return Cache::safeRemember(
            CacheKeys::platformStatistic((int) $brandAgent->id),
            CacheTtl::ONE_HOUR->value,
            fn () => [
                'memberCount' => $this->memberCount($brandAgent),
                'agentCount' => $this->agentCount($brandAgent),
                'totalCommission' => $this->totalCommission($brandAgent),
                'totalEventBonus' => $this->totalEventBonus($brandAgent),
            ],
        );
    }

    /**
     * 註冊會員數：品牌線上狀態正常的會員。
     *
     * `members.status` 0:未認證 1:正常 2:鎖定，只計 1。
     */
    private function memberCount(Agent $brandAgent): int
    {
        return DB::table('members')
            ->where('status', 1)
            ->whereIn('parent', $this->lineAgentIds($brandAgent))
            ->count();
    }

    /**
     * 代理數：品牌線上的下級代理。
     *
     * - 不含品牌根代理自己——那是營運方帳號，不是招募來的代理。
     * - `host_id != 0` 是後台子帳號，同一個代理開的分身，不重複計。
     * - 系統保留帳號（`agents` 表註解的 id 3~100）掛在平台 admin 底下，不在品牌線內，
     *   所以不必另外排除 id。
     */
    private function agentCount(Agent $brandAgent): int
    {
        $prefix = $this->linePrefix($brandAgent);

        if ($prefix === null) {
            return 0;
        }

        return DB::table('agents')
            ->where('status', 1)
            ->where('host_id', 0)
            ->where('hierarchy_path', 'like', $prefix . '%')
            ->count();
    }

    /**
     * 累計代理佣金：**會員端推薦佣金**（三層代 ＋ 無限代），全歷史。
     *
     * ⚠ 兩張表的 `bonus_amount` 語意不對稱：
     *   `three_hierarchy_summary_bonus.bonus_amount` 是**總獎金**（已含提領），直接加；
     *   `unlimit_summary_bonus.bonus_amount` 只是**未提領**，必須補回 `withdraw_amount`，否則少算。
     *
     * 另一套「營運端代理佣金」（`three_hierarchy_summary_bonus_agents` /
     * `unlimit_bonusreport_agents` / `transaction_log_agents type=4`）不採用：那三張表在本專案
     * 沒有任何程式讀過，`transaction_log_agents.type` 又是外部客服系統從 request body 帶進來的，
     * 資料完整性未經驗證。要改口徑請先手動驗資料。
     *
     * 兩張表都是「一個會員一列」，SUM 很便宜。
     */
    private function totalCommission(Agent $brandAgent): string
    {
        $scope = $this->lineAgentIds($brandAgent);

        $threeHierarchy = DB::table('three_hierarchy_summary_bonus as b')
            ->join('members as m', 'm.id', '=', 'b.member_id')
            ->whereIn('m.parent', $scope)
            ->sum('b.bonus_amount');

        $unlimit = DB::table('unlimit_summary_bonus as b')
            ->join('members as m', 'm.id', '=', 'b.member_id')
            ->whereIn('m.parent', $scope)
            ->sum(DB::raw('b.bonus_amount + b.withdraw_amount'));

        return Common::numAdd($this->money($threeHierarchy), $this->money($unlimit), 4);
    }

    /**
     * 累計活動獎金：`transaction_logs` ＋ 多錢包 `wallet_transaction_logs`，全歷史成功交易。
     *
     * 交易類型直接取 `AgentDashboardService` 的 public accessor——那是運營在對的口徑，
     * 兩邊必須是同一份清單，不複製。
     *
     * ⚠ 已知缺口：該清單不含 `TransactionEnum::EVENT_V2_BONUS`(71)，所以活動 V2 的獎金
     *   沒算進來（活動 V2 原本靠 `summary_event_records.event_id = 7`，但 `database/procedures/`
     *   裡沒有任何 SP 會寫 event_id 7）。要不要補是 PM 決策，且會同時改動代理數據看板的數字，
     *   故此處維持與看板一致，不片面加。
     */
    private function totalEventBonus(Agent $brandAgent): string
    {
        $scope = $this->lineAgentIds($brandAgent);
        $success = TransactionStatusEnum::STATUS_SUCCESS->value;

        $sources = [
            ['transaction_logs', AgentDashboardService::eventRewardTxTypes()],
            ['wallet_transaction_logs', AgentDashboardService::eventRewardWalletTxTypes()],
        ];

        $total = '0';

        foreach ($sources as [$table, $types]) {
            $sum = DB::table($table . ' as t')
                ->join('members as m', 'm.id', '=', 't.member_id')
                ->where('t.status', $success)
                ->whereIn('t.type', $types)
                ->whereIn('m.parent', $scope)
                ->sum('t.point');

            $total = Common::numAdd($total, $this->money($sum), 4);
        }

        return $total;
    }

    /**
     * 品牌線的代理 id 子查詢（品牌根代理自己＋所有下級）。
     *
     * 用子查詢而非字面 id 清單：品牌線動輒數百至數千個代理，過長的 IN 清單會讓 MySQL
     * 放棄 `members.parent` 索引（理由同 `AgentDashboardService::scopeFilter()`）。
     */
    private function lineAgentIds(Agent $brandAgent): Closure
    {
        $rootId = (int) $brandAgent->id;
        $prefix = $this->linePrefix($brandAgent);

        return function ($query) use ($rootId, $prefix) {
            $query->select('id')
                ->from('agents')
                ->where(function ($where) use ($rootId, $prefix) {
                    $where->where('id', $rootId);

                    if ($prefix !== null) {
                        $where->orWhere('hierarchy_path', 'like', $prefix . '%');
                    }
                });
        };
    }

    /**
     * 下級比對用的路徑前綴，結尾補「/」當節點邊界，避免 `/1/10009` 誤中 `/1/100092`。
     *
     * `hierarchy_path` 是空的（資料未回填）時回 null——此時只能認得根代理自己，
     * 不能退回 `LIKE '/%'`，那會把整個 DB 的所有品牌都算進來。
     */
    private function linePrefix(Agent $brandAgent): ?string
    {
        $path = rtrim((string) $brandAgent->hierarchy_path, '/');

        return $path === '' ? null : $path . '/';
    }

    /** DB decimal 讀出後正規化成 4 位小數字串再參與運算（與代理看板同一規則） */
    private function money(mixed $value): string
    {
        return Common::numAdd('0', (string) ($value ?? '0'), 4);
    }
}
