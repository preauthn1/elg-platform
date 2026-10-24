<?php

declare(strict_types=1);

namespace App\Services\Agent\Report;

use App\CacheKeys\CacheKeys;
use App\Enums\OrderStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionLogAgentStatusEnum;
use App\Enums\Transaction\TransactionLogAgentTypeEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\SummaryBetRecord;
use App\Services\Agent\ActiveMemberCriteria;
use App\Services\Agent\AgentDashboardService;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SR28 代理營運彙總表（target/tickets#4362）。
 *
 * 以代理為單位，把查詢期間內的註冊、活躍、存提、下注、公司輸贏、獎金與調整彙總成一列，共 25 欄
 * （需求文件「二、25 欄定義」）。貨幣欄與多幣種本案不做。
 *
 * ## 列的範圍（使用者 2026-10-06 定案）
 *
 * - 查詢代理**整條線上的每一個代理各一列**（攤平，不分層），每列只算該代理的**直屬會員**。
 * - 合計＝查詢代理整條線，恰好等於各列相加（每位會員只屬一個直屬代理，各列的會員不重疊）。
 * - 依組織樹排序：上級在前、它的下級緊接在後（依 hierarchy_path 逐段比較）。
 * - 期間內沒有任何數據的代理不列出（查詢代理本人一律列出），整條線攤平後代理很多，全列會是一片 0；
 *   代理錢包餘額是即時值、不算「期間內的數據」。合計的錢包餘額＝列出的代理餘額相加（含其他頁）。
 *
 * ## 建在代理數據看板的聚合框架上
 *
 * 每個指標都 `GROUP BY members.parent` 成「直屬代理 ⇒ 指標」map（看板的 `finish()`），再依列的範圍
 * 把對應的代理 id 加總（`foldMetrics()`）。範疇（`resolveTargetAgent()`／`resolveScope()`）、
 * 時區換算（`range()`）、快取存活時間，以及註冊、活動獎勵、返水、事件類成本四項指標直接用看板的
 * 實作，口徑只有一份（看板 class docblock 列了共用清單）。看板沒有、或口徑不同的指標在本類別自己查：
 *
 * | 欄位 | 來源 | 與看板的差異 |
 * |---|---|---|
 * | 首存人數／金額 | orders 全歷史第一筆成功充值落在期間內 | 看板只算期間內新註冊的會員（漏斗子集）；本表依規格不限註冊時間 |
 * | 存提筆數／金額 | member_hourly_statistics 的 count／amount | 看板算人數，本表算筆數 |
 * | 存款手續費 | summary_event_records event 3（支付商成本） | 同看板的「支付手續費」（使用者指定） |
 * | 提款手續費 | withdrawal_orders.tax × rate（#4353 平台手續費，換成平台點數，比照 SR02N） | 看板沒有 |
 * | 下注五欄、GGR | summary_bet_records，`SummaryBetRecord::betMetricSelects()` | 看板排除 SR11 不預選的遊戲商、沒有 4D 特例；本表比照 SR29 |
 * | 調整 | transaction_logs type 14（調入）／36（調出） | 看板沒有 |
 *
 * ## 待 PM 確認、目前的暫定做法
 *
 * - **活躍玩家**（使用者 2026-10-07 定案）：沿用 #4121 的「判斷活躍玩家條件」7006 充值／7007 有效流水，
 *   門檻取各列代理自己的個性化組別、與代理模式無關；期間內有充值或下注、且達成有設定的門檻才算，
 *   兩門檻皆 0＝只看有無充值或下注。有效流水用 summary_bet_records（與本表有效下注同源），見 `activeByAgent()`。
 * - **發出獎金一律取實際領取**（使用者 2026-10-07 定案）：活動獎勵＋返水＋佣金。
 *   - 活動獎勵：交易表 17 種活動類型＋多錢包活動派彩（同看板）＋活動 V2 提領（交易類型 71，見
 *     `eventV2PayoutByAgent()`）＋VIP 獎勵（summary_event_records event 9，來源交易類型 32，每小時重建）。
 *     🟡 活動 V2 的提領金額含參加時從主錢包轉入的本金（type 70），是否扣除待 PM 確認。
 *   - 返水（會員領取）：三層代 three_hierarchy_withdraw_logs、無限代 unlimit_withdrawlog，見 `rebatePayoutByAgent()`。
 *     🟡 三層代只計平台點數（type 1），禮碼（type 2）是否計入待確認。
 *   - 佣金（代理領取）：代理帳變 transaction_log_agents type 3，見 `commissionPayoutByAgent()`。
 *     三層代與無限代的代理返水都寫 type 3、無法區分，目前一併算佣金（不影響發出獎金總額）。
 * - 調入不含會員管理的「調帳」（type 33），使用者 2026-10-06 定案。
 * - 下注金額與有效下注恆等（彙總表沒有原始下注額），比照 SR29，使用者 2026-10-06 定案。
 *
 * ## 金額
 *
 * DB decimal 讀出一律 `Common::numAdd('0', …, 4)` 正規化後再運算；推導欄（充提淨額、GGR、NGR、
 * 調整淨額）由本類別算好回傳，前端只顯示。
 */
class AgentOperationSummaryService
{
    private const int SCALE = 4;

    /** 金額欄的零值，與有資料時 numAdd 的輸出格式一致（4 位小數） */
    private const string ZERO = '0.0000';

    private const int DEFAULT_PER_PAGE = 20;

    /** 調入：後台補點（補帳） */
    private const array ADJUST_IN_TYPES = [TransactionEnum::MAKE_UP->value];

    /** 調出：後台扣分 */
    private const array ADJUST_OUT_TYPES = [TransactionEnum::DEDUCT->value];

    /**
     * 三層代會員領取（three_hierarchy_withdraw_logs.type）：1 平台點數、2 禮碼（rolling_chip_logs）。
     * 禮碼是否算發出獎金待確認，目前只計平台點數；確認要計時在此補 2。
     */
    private const array THREE_HIERARCHY_PAYOUT_TYPES = [1];

    /** three_hierarchy_withdraw_logs.status：1 成功（0 失敗） */
    private const int THREE_HIERARCHY_PAYOUT_SUCCESS = 1;

    /** unlimit_withdrawlog.type：1 已發放（0 未發放、2 拒絕）；領取當下即寫 1 並入主錢包 */
    private const int UNLIMIT_PAYOUT_PAID = 1;

    /** 提款成功：自助洗分（已轉帳）與三方代付成功，與 member_hourly_statistics 的提款口徑一致 */
    private const array WITHDRAWAL_SUCCESS_STATUSES = [
        WithdrawalOrderStatusEnum::TRANSFERRED->value,
        WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value,
    ];

    public function __construct(
        private readonly AgentDashboardService $dashboard,
    ) {}

    /** 查詢對象：未指定帳號＝登入者本人（子帳號以主帳號為準）；範疇外回 null，規則同代理數據看板 */
    public function resolveTarget(Agent $viewer, ?string $username): ?Agent
    {
        return $this->dashboard->resolveTargetAgent($viewer, $username);
    }

    /**
     * @param  array<string, mixed>  $input  start_time／end_time（使用者時區牆鐘）、tz_offset、page、per_page
     * @return array<string, mixed>
     */
    public function list(Agent $viewer, Agent $target, array $input): array
    {
        $scope = $this->dashboard->resolveScope($viewer, $target, ['scope' => 'line']);
        /** @var Collection<int, object> $agents */
        $agents = $scope['agents'];
        $maps = $this->aggregate($scope['line_ids'], $input, $scope['line_signature']);

        $rows = $this->rowsOf($agents, $target, $maps);
        $balances = $this->walletBalances($scope['line_ids']);

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = (int) ($input['per_page'] ?? self::DEFAULT_PER_PAGE);

        $list = $rows->forPage($page, $perPage)
            ->map(fn (array $row) => [
                'agent_id' => $row['agent_id'],
                'agent_username' => $row['agent_username'],
                ...$this->withBalance($row['metrics'], $balances[$row['agent_id']] ?? self::ZERO),
            ])
            ->values()
            ->all();

        // 合計＝整條線（各列的會員不重疊，等於全部列相加）；錢包餘額取列出的代理（不限當頁）的加總
        $summary = $this->withBalance(
            $this->foldMetrics($maps, null),
            $rows->reduce(
                fn (string $carry, array $row) => Common::numAdd($carry, $balances[$row['agent_id']] ?? self::ZERO, self::SCALE),
                self::ZERO,
            ),
        );

        return [
            'list' => $list,
            'summary' => $summary,
            'pagination' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
                'per_page' => $perPage,
                'total' => $rows->count(),
            ],
            'time_basis' => $this->dashboard->timezoneOf((int) $input['tz_offset']),
        ];
    }

    /**
     * 列：查詢代理整條線的每個代理各一列、只算直屬會員，依組織樹排序；期間內沒有數據的不列（本人一律列）。
     *
     * @param  Collection<int, object>  $agents  登入者可視範疇內的代理（看板 resolveScope 的 agents）
     * @return Collection<int, array{agent_id: int, agent_username: string, metrics: array<string, int|string>}>
     */
    private function rowsOf(Collection $agents, Agent $target, array $maps): Collection
    {
        $targetId = (int) $target->id;
        $targetRow = $agents->get($targetId);
        $lineIds = $targetRow === null ? [$targetId] : $this->dashboard->lineIds($agents, $targetRow);

        return collect($lineIds)
            ->map(fn (int $id) => $agents->get($id) ?? (object) [
                'id' => $id,
                'username' => $target->username,
                'hierarchy_path' => $target->hierarchy_path,
            ])
            ->sort(fn (object $a, object $b) => $this->compareTreeOrder((string) $a->hierarchy_path, (string) $b->hierarchy_path))
            ->map(fn (object $agent) => [
                'agent_id' => (int) $agent->id,
                'agent_username' => (string) $agent->username,
                'metrics' => $this->foldMetrics($maps, [(int) $agent->id]),
            ])
            ->filter(fn (array $row) => $row['agent_id'] === $targetId || $this->hasAnyValue($row['metrics']))
            ->values();
    }

    /**
     * 組織樹順序：hierarchy_path（形如 `/1/4249/4250`，含自己）逐段以數字比較，上級排在下級前面。
     * 不能直接比字串（`/1/42490` 會排進 `/1/4249/…` 中間），也不能用 PHP 的陣列 `<=>`（它先比長度）。
     */
    private function compareTreeOrder(string $a, string $b): int
    {
        $nodesA = array_map(intval(...), array_values(array_filter(explode('/', $a), fn (string $n) => $n !== '')));
        $nodesB = array_map(intval(...), array_values(array_filter(explode('/', $b), fn (string $n) => $n !== '')));

        foreach ($nodesA as $i => $node) {
            if (!isset($nodesB[$i])) {
                return 1;
            }
            if ($node !== $nodesB[$i]) {
                return $node <=> $nodesB[$i];
            }
        }

        return count($nodesA) <=> count($nodesB);
    }

    /**
     * 代理錢包餘額：`agents.balance` 當下即時值，不隨查詢期間變動（需求文件規定畫面要註明口徑）。
     *
     * 以看板的範疇條件查（代理多時是 hierarchy_path 子查詢），不送整串 id：平台端整條線可達數萬個代理。
     *
     * @param  array<int, int>|Closure|null  $lineIds  看板 resolveScope 的 line_ids
     * @return array<int, string> agentId ⇒ 餘額
     */
    private function walletBalances(array|Closure|null $lineIds): array
    {
        return $this->dashboard->applyScope(DB::table('agents'), 'id', $lineIds)
            ->pluck('balance', 'id')
            ->mapWithKeys(fn ($balance, $id) => [(int) $id => $this->money($balance)])
            ->all();
    }

    /**
     * 全部指標的「直屬代理 ⇒ 指標」map。快取鍵與存活時間比照看板：已結束的區間放一小時、涵蓋今天的放短。
     *
     * @param  array<int, int>|Closure|null  $agentIds
     * @return array<string, array<int, mixed>>
     */
    private function aggregate(array|Closure|null $agentIds, array $input, string $signature): array
    {
        $key = CacheKeys::agentOperationSummaryAggregate(implode('|', [
            $signature,
            $input['start_time'],
            $input['end_time'],
            $input['tz_offset'],
        ]));

        return Cache::safeRemember(
            $key,
            $this->dashboard->aggregateTtl($input),
            fn () => $this->runAggregate($agentIds, $input),
        );
    }

    /**
     * @param  array<int, int>|Closure|null  $agentIds
     * @return array<string, array<int, mixed>>
     */
    private function runAggregate(array|Closure|null $agentIds, array $input): array
    {
        [$startAt, $endAt] = $this->dashboard->range($input);

        return [
            'registration' => $this->dashboard->registrationByAgent($agentIds, $startAt, $endAt, true),
            'first_deposit' => $this->firstDepositByAgent($agentIds, $startAt, $endAt),
            'cash' => $this->cashByAgent($agentIds, $startAt, $endAt),
            'withdrawal_fee' => $this->withdrawalFeeByAgent($agentIds, $startAt, $endAt),
            'bet' => $this->betByAgent($agentIds, $startAt, $endAt),
            'active' => $this->activeByAgent($agentIds, $startAt, $endAt),
            'cost' => $this->dashboard->costByAgent($agentIds, $startAt, $endAt, true),
            'event_reward' => $this->dashboard->eventRewardByAgent($agentIds, $startAt, $endAt, true),
            'event_v2' => $this->eventV2PayoutByAgent($agentIds, $startAt, $endAt),
            'rebate' => $this->rebatePayoutByAgent($agentIds, $startAt, $endAt),
            'commission' => $this->commissionPayoutByAgent($agentIds, $startAt, $endAt),
            'adjustment' => $this->adjustmentByAgent($agentIds, $startAt, $endAt),
        ];
    }

    /**
     * 首存：會員全歷史第一筆成功充值落在期間內（需求文件「期間內首次存款的會員數」，不限註冊時間）。
     *
     * 不用 `members.first_deposit`：看板實測該欄位幾乎沒寫入。以「期間內的成功充值、且之前沒有更早的
     * 成功充值」判定第一筆，NOT EXISTS 走 orders 的 (member_id, status, …) 索引逐人比對，不必對全歷史
     * GROUP BY。同一秒兩筆時以 id 小的那筆為首存，人數與金額都只算一筆。
     *
     * @param  array<int, int>|Closure|null  $agentIds
     */
    private function firstDepositByAgent(array|Closure|null $agentIds, string $startAt, string $endAt): array
    {
        $success = OrderStatusEnum::DEPOSIT_FINISHED->value;

        $query = $this->dashboard->applyScope(
            DB::table('orders as o')
                ->join('members as m', 'm.id', '=', 'o.member_id')
                ->where('o.status', $success)
                ->whereBetween('o.pay_time', [$startAt, $endAt])
                ->whereNotExists(function (Builder $earlier) use ($success) {
                    $earlier->select(DB::raw(1))
                        ->from('orders as p')
                        ->whereColumn('p.member_id', 'o.member_id')
                        ->where('p.status', $success)
                        ->where(function (Builder $before) {
                            $before->whereColumn('p.pay_time', '<', 'o.pay_time')
                                ->orWhere(function (Builder $sameSecond) {
                                    $sameSecond->whereColumn('p.pay_time', '=', 'o.pay_time')
                                        ->whereColumn('p.id', '<', 'o.id');
                                });
                        });
                }),
            'm.parent',
            $agentIds,
        );

        return $this->dashboard->finish($query, 'm.parent', true, [
            DB::raw('COUNT(*) as ftd_count'),
            DB::raw('SUM(o.point) as ftd_amount'),
        ]);
    }

    /**
     * 成功存提的筆數與金額：member_hourly_statistics（成功狀態已在寫入端固化，含後台開洗分，
     * target/tickets#4333 口徑，與看板、數據分析一致）。整點桶，起點對齊整點才不會漏掉起始的部分小時。
     *
     * @param  array<int, int>|Closure|null  $agentIds
     */
    private function cashByAgent(array|Closure|null $agentIds, string $startAt, string $endAt): array
    {
        $startHour = Carbon::parse($startAt)->startOfHour()->toDateTimeString();

        $query = $this->dashboard->applyScope(
            DB::table('member_hourly_statistics as mhs')
                ->join('members as m', 'm.id', '=', 'mhs.member_id')
                ->whereBetween('mhs.summary_hour', [$startHour, $endAt]),
            'm.parent',
            $agentIds,
        );

        return $this->dashboard->finish($query, 'm.parent', true, [
            DB::raw('SUM(mhs.deposit_count) as deposit_count'),
            DB::raw('SUM(mhs.deposit_amount) as deposit_amount'),
            DB::raw('SUM(mhs.withdrawal_count) as withdrawal_count'),
            DB::raw('SUM(mhs.withdrawal_amount) as withdrawal_amount'),
        ]);
    }

    /**
     * 提款手續費：#4353 的平台手續費 `withdrawal_orders.tax`，乘 `rate` 換成平台點數（比照 SR02N），
     * 以成功出款時間 `payment_time` 落期間（走 (status, payment_time) 索引）。
     *
     * @param  array<int, int>|Closure|null  $agentIds
     */
    private function withdrawalFeeByAgent(array|Closure|null $agentIds, string $startAt, string $endAt): array
    {
        $query = $this->dashboard->applyScope(
            DB::table('withdrawal_orders as w')
                ->join('members as m', 'm.id', '=', 'w.member_id')
                ->whereIn('w.status', self::WITHDRAWAL_SUCCESS_STATUSES)
                ->whereBetween('w.payment_time', [$startAt, $endAt]),
            'm.parent',
            $agentIds,
        );

        return $this->dashboard->finish($query, 'm.parent', true, [
            DB::raw('SUM(w.tax * w.rate) as withdrawal_fee'),
        ]);
    }

    /**
     * 下注五欄：summary_bet_records，口徑與 SR29 共用 `SummaryBetRecord::betMetricSelects()`
     * （4D 類 win_lose 即派彩的特例在裡面），不排除任何遊戲商（同 SR29）。
     *
     * 選取欄帶綁定值，不能走看板的 `finish()`（`select()` 會清掉 select 綁定），這裡自己分組。
     *
     * @param  array<int, int>|Closure|null  $agentIds
     */
    private function betByAgent(array|Closure|null $agentIds, string $startAt, string $endAt): array
    {
        [$selects, $bindings] = SummaryBetRecord::betMetricSelects('sbr');

        return $this->dashboard->applyScope(
            DB::table('summary_bet_records as sbr')
                ->join('members as m', 'm.id', '=', 'sbr.member_id')
                ->whereBetween('sbr.summary_time', [$startAt, $endAt]),
            'm.parent',
            $agentIds,
        )
            ->groupBy('m.parent')
            ->selectRaw("m.parent as parent, {$selects}", $bindings)
            ->get()
            ->keyBy('parent')
            ->all();
    }

    /**
     * 活躍玩家（使用者 2026-10-07 定案）：期間內**有充值或下注**、且達成直屬代理所屬個性化組別的
     * 活躍門檻（7006 充值／7007 有效流水，`ActiveMemberCriteria`）的會員數。兩門檻皆 0＝只看有無充值或下注。
     *
     * - 充值＝member_hourly_statistics.deposit_amount（同存款欄），有效流水＝summary_bet_records.bet
     *   （同本表的「有效下注」欄），兩者時間窗同各自的欄位。
     * - 門檻依「各列代理自己的組別」取，與代理模式無關；同組別只解析一次（getSettingValue 另有快取）。
     * - 先在兩張表各自依會員加總再 UNION，外層依會員合併，避免把整段期間的明細列搬進衍生表。
     *   結果一位會員一列，以 cursor 逐列比對門檻，不一次載入記憶體。
     *
     * @param  array<int, int>|Closure|null  $agentIds
     * @return array<int, object{active_player_count: int}>
     */
    private function activeByAgent(array|Closure|null $agentIds, string $startAt, string $endAt): array
    {
        $startHour = Carbon::parse($startAt)->startOfHour()->toDateTimeString();

        $deposits = $this->dashboard->applyScope(
            DB::table('member_hourly_statistics as mhs')
                ->join('members as m', 'm.id', '=', 'mhs.member_id')
                ->whereBetween('mhs.summary_hour', [$startHour, $endAt]),
            'm.parent',
            $agentIds,
        )
            ->groupBy('mhs.member_id')
            ->havingRaw('SUM(mhs.deposit_amount) > 0')
            ->selectRaw('mhs.member_id, SUM(mhs.deposit_amount) as deposit, 0 as valid_bet');

        $bets = $this->dashboard->applyScope(
            DB::table('summary_bet_records as sbr')
                ->join('members as m', 'm.id', '=', 'sbr.member_id')
                ->whereBetween('sbr.summary_time', [$startAt, $endAt]),
            'm.parent',
            $agentIds,
        )
            ->groupBy('sbr.member_id')
            ->selectRaw('sbr.member_id, 0 as deposit, SUM(sbr.bet) as valid_bet');

        $members = DB::query()
            ->fromSub($deposits->unionAll($bets), 'u')
            ->join('members as m', 'm.id', '=', 'u.member_id')
            ->join('agents as a', 'a.id', '=', 'm.parent')
            ->groupBy('u.member_id', 'm.parent', 'a.customized_setting_group_id')
            ->selectRaw('m.parent as parent, a.customized_setting_group_id as group_id, SUM(u.deposit) as deposit, SUM(u.valid_bet) as valid_bet');

        /** @var array<int, ActiveMemberCriteria> $criteriaByGroup */
        $criteriaByGroup = [];
        $counts = [];

        foreach ($members->cursor() as $row) {
            $groupId = $row->group_id === null ? null : (int) $row->group_id;
            $criteria = $criteriaByGroup[$groupId ?? 0] ??= ActiveMemberCriteria::forSettingGroup($groupId);

            if ($criteria->passes($this->money($row->deposit), $this->money($row->valid_bet))) {
                $parent = (int) $row->parent;
                $counts[$parent] = ($counts[$parent] ?? 0) + 1;
            }
        }

        return array_map(fn (int $count) => (object) ['active_player_count' => $count], $counts);
    }

    /**
     * 調整：後台人工加點／扣點（transaction_logs，成功狀態）。
     * 調入＝補帳 type 14、調出＝扣分 type 36；扣分的 point 不論正負一律取絕對值當「調出金額」。
     * 會員管理的「調帳」type 33 不計（使用者 2026-10-06 定案，同數據分析口徑）。
     *
     * @param  array<int, int>|Closure|null  $agentIds
     */
    private function adjustmentByAgent(array|Closure|null $agentIds, string $startAt, string $endAt): array
    {
        $in = implode(',', self::ADJUST_IN_TYPES);
        $out = implode(',', self::ADJUST_OUT_TYPES);

        $query = $this->dashboard->applyScope(
            DB::table('transaction_logs as t')
                ->join('members as m', 'm.id', '=', 't.member_id')
                ->where('t.status', TransactionStatusEnum::STATUS_SUCCESS->value)
                ->whereIn('t.type', [...self::ADJUST_IN_TYPES, ...self::ADJUST_OUT_TYPES])
                ->whereBetween('t.created_at', [$startAt, $endAt]),
            'm.parent',
            $agentIds,
        );

        // type 清單是本類別的整數常數，直接組進 SQL 沒有注入風險
        return $this->dashboard->finish($query, 'm.parent', true, [
            DB::raw('COUNT(*) as adjust_count'),
            DB::raw("SUM(CASE WHEN t.type IN ({$in}) THEN ABS(t.point) ELSE 0 END) as adjust_in"),
            DB::raw("SUM(CASE WHEN t.type IN ({$out}) THEN ABS(t.point) ELSE 0 END) as adjust_out"),
        ]);
    }

    /**
     * 返水（會員實際領取，使用者 2026-10-07 定案「發出獎金均為實際領取」），以會員的直屬代理歸戶：
     *
     * - 三層代：three_hierarchy_withdraw_logs（自動派發 SP_payout_three_hierarchy_bonus 與前台手動領取都寫，
     *   一筆＝一次實際領取），成功狀態、限 `THREE_HIERARCHY_PAYOUT_TYPES`，同 SR27 #14 的來源。
     * - 無限代：unlimit_withdrawlog（前台領取當下即寫「已發放」並入主錢包），金額＝提領金額＋提領自身回水。
     *
     * 兩者皆以 created_at（領取時間）落期間。不用看板的 rebateByAgent()：那是每日應得報表。
     *
     * @param  array<int, int>|Closure|null  $agentIds
     * @return array<int, string> agentId ⇒ 金額
     */
    private function rebatePayoutByAgent(array|Closure|null $agentIds, string $startAt, string $endAt): array
    {
        $sources = [
            [
                DB::table('three_hierarchy_withdraw_logs as t')
                    ->join('members as m', 'm.id', '=', 't.member_id')
                    ->where('t.status', self::THREE_HIERARCHY_PAYOUT_SUCCESS)
                    ->whereIn('t.type', self::THREE_HIERARCHY_PAYOUT_TYPES),
                'SUM(t.withdraw_point)',
            ],
            [
                DB::table('unlimit_withdrawlog as t')
                    ->join('members as m', 'm.id', '=', 't.member_id')
                    ->where('t.type', self::UNLIMIT_PAYOUT_PAID),
                'SUM(t.amount + t.itself_amount)',
            ],
        ];

        $result = [];

        foreach ($sources as [$query, $sum]) {
            $query = $this->dashboard->applyScope($query->whereBetween('t.created_at', [$startAt, $endAt]), 'm.parent', $agentIds);

            foreach ($this->dashboard->finish($query, 'm.parent', true, [DB::raw($sum . ' as amount')]) as $agentId => $row) {
                $result[(int) $agentId] = Common::numAdd($result[(int) $agentId] ?? '0', $this->money($row->amount), self::SCALE);
            }
        }

        return $result;
    }

    /**
     * 活動 V2 實際領取：活動錢包提回主錢包時寫的交易類型 71（`AbstractEventV2::withdraw()`），成功狀態、
     * 以 created_at 落期間、會員的直屬代理歸戶。取代看板用的活動 V2 成本（summary_event_records event 7，
     * 參加／完成時認列）。
     *
     * 金額是提領金額：參加時若有從主錢包扣款轉入活動錢包（type 70），那筆本金也包含在內；是否要扣除本金
     * 待 PM 確認（見 class docblock）。積分型（withdraw_type=2）不進活動錢包、不寫 type 71，自然不計。
     *
     * @param  array<int, int>|Closure|null  $agentIds
     * @return array<int, string> agentId ⇒ 金額
     */
    private function eventV2PayoutByAgent(array|Closure|null $agentIds, string $startAt, string $endAt): array
    {
        $query = $this->dashboard->applyScope(
            DB::table('transaction_logs as t')
                ->join('members as m', 'm.id', '=', 't.member_id')
                ->where('t.type', TransactionEnum::EVENT_V2_BONUS->value)
                ->where('t.status', TransactionStatusEnum::STATUS_SUCCESS->value)
                ->whereBetween('t.created_at', [$startAt, $endAt]),
            'm.parent',
            $agentIds,
        );

        return collect($this->dashboard->finish($query, 'm.parent', true, [DB::raw('SUM(t.point) as amount')]))
            ->mapWithKeys(fn ($row, $agentId) => [(int) $agentId => $this->money($row->amount)])
            ->all();
    }

    /**
     * 佣金（代理實際領取的三層代代理返水）：代理帳變 transaction_log_agents type 3「全民代理」、成功狀態，
     * 歸戶到收款代理（`to_agent_id`）本人，以 created_at 落期間。
     *
     * 由舊系統 API_Frontend 的 agentThreeHierarchyBonus／agentUnlimitBonus 派發到代理餘額；兩者都寫 type 3、
     * 無法區分三層代與無限代，目前一併計入佣金（只影響歸類，不影響發出獎金總額，見 class docblock）。
     *
     * @param  array<int, int>|Closure|null  $agentIds
     * @return array<int, string> agentId ⇒ 金額
     */
    private function commissionPayoutByAgent(array|Closure|null $agentIds, string $startAt, string $endAt): array
    {
        $query = $this->dashboard->applyScope(
            DB::table('transaction_log_agents as t')
                ->where('t.type', TransactionLogAgentTypeEnum::AGENT->value)
                ->where('t.status', TransactionLogAgentStatusEnum::SUCCESS->value)
                ->whereBetween('t.created_at', [$startAt, $endAt]),
            't.to_agent_id',
            $agentIds,
        );

        return collect($this->dashboard->finish($query, 't.to_agent_id', true, [DB::raw('SUM(t.amount) as amount')]))
            ->mapWithKeys(fn ($row, $agentId) => [(int) $agentId => $this->money($row->amount)])
            ->all();
    }

    /**
     * 把「直屬代理 ⇒ 指標」map 收斂成一列的 24 個數字欄（錢包餘額另外補）。
     * `$agentIds` 為 null 時取 map 內全部代理（整條線的合計）。
     *
     * @param  array<string, array<int, mixed>>  $maps
     * @param  array<int, int>|null  $agentIds
     * @return array<string, int|string>
     */
    private function foldMetrics(array $maps, ?array $agentIds): array
    {
        $agentIds ??= collect($maps)
            ->flatMap(fn (array $rows) => array_keys($rows))
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->all();

        $counts = array_fill_keys([
            'registration_count', 'ftd_count', 'deposit_count', 'withdrawal_count',
            'bet_count', 'active_player_count', 'adjust_count',
        ], 0);
        $amounts = array_fill_keys([
            'ftd_amount', 'deposit_amount', 'withdrawal_amount', 'withdrawal_fee',
            'bet_amount', 'valid_bet', 'payout', 'member_win_lose', 'adjust_in', 'adjust_out',
        ], self::ZERO);
        $depositFee = self::ZERO;
        $eventReward = self::ZERO;
        $rebate = self::ZERO;
        $commission = self::ZERO;

        foreach ($agentIds as $agentId) {
            foreach (['registration', 'first_deposit', 'cash', 'withdrawal_fee', 'bet', 'active', 'adjustment'] as $source) {
                $row = $maps[$source][$agentId] ?? null;
                if ($row === null) {
                    continue;
                }
                foreach ($counts as $key => $value) {
                    if (isset($row->{$key})) {
                        $counts[$key] = $value + (int) $row->{$key};
                    }
                }
                foreach ($amounts as $key => $value) {
                    if (isset($row->{$key})) {
                        $amounts[$key] = Common::numAdd($value, $this->money($row->{$key}), self::SCALE);
                    }
                }
            }

            $cost = $maps['cost'][$agentId] ?? [];
            $depositFee = Common::numAdd($depositFee, $cost['payment_fee'] ?? '0', self::SCALE);
            // 活動獎勵（實際入帳）＝交易表 17 種活動類型＋多錢包活動派彩＋活動 V2 提領（type 71）＋VIP 獎勵（event 9）
            $eventReward = Common::numAdd($eventReward, $maps['event_v2'][$agentId] ?? '0', self::SCALE);
            $eventReward = Common::numAdd($eventReward, $cost['vip'] ?? '0', self::SCALE);
            $eventReward = Common::numAdd(
                $eventReward,
                $this->money($maps['event_reward'][$agentId]->event_reward ?? '0'),
                self::SCALE,
            );
            $rebate = Common::numAdd($rebate, $maps['rebate'][$agentId] ?? '0', self::SCALE);
            $commission = Common::numAdd($commission, $maps['commission'][$agentId] ?? '0', self::SCALE);
        }

        $bonus = Common::numAdd(Common::numAdd($eventReward, $rebate, self::SCALE), $commission, self::SCALE);
        $ggr = Common::numMul($amounts['member_win_lose'], '-1', self::SCALE);

        return [
            'register_count' => $counts['registration_count'],
            'active_player_count' => $counts['active_player_count'],
            'ftd_count' => $counts['ftd_count'],
            'ftd_amount' => $amounts['ftd_amount'],
            'deposit_count' => $counts['deposit_count'],
            'deposit_amount' => $amounts['deposit_amount'],
            'deposit_fee' => $depositFee,
            'withdrawal_count' => $counts['withdrawal_count'],
            'withdrawal_amount' => $amounts['withdrawal_amount'],
            'withdrawal_fee' => $amounts['withdrawal_fee'],
            'bet_count' => $counts['bet_count'],
            'bet_amount' => $amounts['bet_amount'],
            'valid_bet' => $amounts['valid_bet'],
            'payout' => $amounts['payout'],
            'member_win_lose' => $amounts['member_win_lose'],
            // 充提淨額＝存款金額 − 提款金額
            'net_deposit' => Common::numSub($amounts['deposit_amount'], $amounts['withdrawal_amount'], self::SCALE),
            // GGR＝遊戲總輸贏（公司角度）＝ −玩家輸贏，同 SR29 的公司輸贏
            'ggr' => $ggr,
            // NGR＝GGR − 發出獎金
            'ngr' => Common::numSub($ggr, $bonus, self::SCALE),
            'bonus' => $bonus,
            'adjust_count' => $counts['adjust_count'],
            'adjust_in' => $amounts['adjust_in'],
            'adjust_out' => $amounts['adjust_out'],
            // 調整淨額＝調入 − 調出
            'adjust_net' => Common::numSub($amounts['adjust_in'], $amounts['adjust_out'], self::SCALE),
        ];
    }

    /**
     * 期間內是否有任何數據（決定直屬下級要不要列出）：人數、筆數、金額任一非零即算。
     *
     * @param  array<string, int|string>  $metrics
     */
    private function hasAnyValue(array $metrics): bool
    {
        foreach ($metrics as $value) {
            if (is_int($value) ? $value !== 0 : Common::numCompare((string) $value, '0', self::SCALE) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 錢包餘額放在第一個數字欄（與前端欄位順序一致）。
     *
     * @param  array<string, int|string>  $metrics
     * @return array<string, int|string>
     */
    private function withBalance(array $metrics, string $balance): array
    {
        return ['wallet_balance' => $balance, ...$metrics];
    }

    /** DB decimal 入境正規化 */
    private function money(mixed $value): string
    {
        return Common::numAdd('0', (string) ($value ?? '0'), self::SCALE);
    }
}
