<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\OrderStatusEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\SummaryBetRecord;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 代理數據看板 — 明細下鑽（target/tickets#4180 第二階段）。
 *
 * 設計要點（完整規劃見 `docs/代理數據看板/api-plan-phase2.md`）：
 *
 * - **一列＝一位會員的期間彙總**，同一會員只出現一次；沒有交易單號、沒有逐筆交易列。
 * - **一組指標一張明細**：下鑽對象是「組」，同組的指標格子點下去開同一張表。組的切法以
 *   「名單」（列出哪些會員）為準，同名單才同組，所以每張表的列數就等於該組人數卡的數字。
 * - **與一階同源**：範疇（`resolveScope`）、時間換算（`range`）、成功狀態、漏斗子集口徑
 *   全部複用 `AgentDashboardService`，明細加總才會等於卡片數字。
 * - **先分頁再聚合**：名單 query 只取 `member_id` 並分頁，再對「當頁會員」逐來源表彙總，
 *   禁止全量 GROUP BY 後在 PHP 分頁。
 * - **時間欄回使用者所選時區**（`tz_offset`），與一階同一套規則。
 */
class AgentDashboardDetailService
{
    /** 每組明細的欄位定義：key ⇒ 語言 key 與型別，前端照這份渲染表頭 */
    private const array COLUMNS = [
        'registration' => [
            ['key' => 'is_first_deposit', 'label_key' => 'ad_col_is_first_deposit', 'type' => 'bool'],
            ['key' => 'first_deposit_at', 'label_key' => 'ad_col_first_deposit_at', 'type' => 'datetime'],
        ],
        'first_deposit' => [
            ['key' => 'first_deposit_at', 'label_key' => 'ad_col_first_deposit_at', 'type' => 'datetime'],
            ['key' => 'first_deposit_amount', 'label_key' => 'ad_first_deposit_amount', 'type' => 'money'],
            ['key' => 'is_repeat_deposit', 'label_key' => 'ad_col_is_repeat_deposit', 'type' => 'bool'],
        ],
        'repeat_deposit' => [
            ['key' => 'first_deposit_at', 'label_key' => 'ad_col_first_deposit_at', 'type' => 'datetime'],
            ['key' => 'repeat_deposit_count', 'label_key' => 'ad_col_count', 'type' => 'int'],
            ['key' => 'repeat_deposit_amount', 'label_key' => 'ad_repeat_deposit_amount', 'type' => 'money'],
        ],
        'deposit' => [
            ['key' => 'first_deposit_hour', 'label_key' => 'ad_col_first_deposit_hour', 'type' => 'datetime'],
            ['key' => 'deposit_count', 'label_key' => 'ad_col_count', 'type' => 'int'],
            ['key' => 'deposit_amount', 'label_key' => 'ad_deposit_amount', 'type' => 'money'],
        ],
        'withdrawal' => [
            ['key' => 'first_withdrawal_hour', 'label_key' => 'ad_col_first_withdrawal_hour', 'type' => 'datetime'],
            ['key' => 'withdrawal_count', 'label_key' => 'ad_col_count', 'type' => 'int'],
            ['key' => 'withdrawal_amount', 'label_key' => 'ad_withdrawal_amount', 'type' => 'money'],
        ],
        'bet' => [
            ['key' => 'first_bet_hour', 'label_key' => 'ad_col_first_bet_hour', 'type' => 'datetime'],
            ['key' => 'bet_count', 'label_key' => 'ad_col_count', 'type' => 'int'],
            ['key' => 'bet_amount', 'label_key' => 'ad_bet_amount', 'type' => 'money'],
            ['key' => 'payout_amount', 'label_key' => 'ad_payout_amount', 'type' => 'money'],
            ['key' => 'ggr', 'label_key' => 'ggr', 'type' => 'money'],
        ],
        'retention' => [
            ['key' => 'login_returned_at', 'label_key' => 'ad_col_login_returned_at', 'type' => 'datetime'],
            ['key' => 'bet_returned_at', 'label_key' => 'ad_col_bet_returned_at', 'type' => 'datetime'],
            ['key' => 'deposit_returned_at', 'label_key' => 'ad_col_deposit_returned_at', 'type' => 'datetime'],
        ],
    ];

    /**
     * 走 `summary_event_records` 的成本組：group ⇒ event_id（與一階同一份對照）。
     *
     * 返水成本（`cost_rebate`）與回收金額（`cost_recycle`）不在此表，各自讀原始表，見 rebateValues() / recycleValues()。
     */
    private const array COST_EVENT_IDS = [
        // 活動成本的主體改抽交易表（見 eventRewardMemberIds / eventRewardValues），此處只留活動 V2
        'cost_event' => [7],
        'cost_rolling_chip' => [6],
        'cost_vip' => [9],
        'cost_payment_fee' => [3],
        'cost_withdrawal_fee' => [8],
        'cost_sms' => [4],
    ];

    /** 成本組的金額欄名（前端表頭沿用一階的指標名） */
    private const array COST_LABELS = [
        'cost_event' => 'ad_cost_event',
        'cost_rolling_chip' => 'ad_cost_rolling_chip',
        'cost_vip' => 'ad_cost_vip',
        'cost_payment_fee' => 'ad_cost_payment_fee',
        'cost_withdrawal_fee' => 'ad_cost_withdrawal_fee',
        'cost_sms' => 'ad_cost_sms',
        'cost_vendor_fee' => 'ad_cost_vendor_fee',
        // 返水（三層代／無限代）只列發給會員的部分，代理本人份無會員可歸戶 ⇒ 明細加總小於卡片；回收可完整歸戶
        'cost_rebate' => 'ad_cost_rebate',
        'cost_recycle' => 'ad_cost_recycle',
    ];

    /**
     * 充提六類來源明細組 ⇒ `member_hourly_statistics` 欄位前綴（與一階指標卡同一欄，卡片＝明細加總）。
     *
     * 來源定義由 `MemberHourlyStatisticsRecomputeService` 寫入（target/tickets#4333）：三方充值／自助上分（orders 依金流商
     * 是否 AutoDeposit）、後台開分（transaction_logs type 4、order_id 0）、三方提現（withdrawal_orders status 24）／
     * 自助洗分（status 4）、後台洗分（type 5、order_id 0）。明細不重查來源表，直接讀同一欄，篩選口徑才不會分岔。
     */
    private const array HOURLY_SOURCE_GROUPS = [
        'deposit_third_party' => ['prefix' => 'third_party_deposit', 'kind' => 'deposit', 'label' => 'ad_third_party_deposit_amount'],
        'deposit_auto' => ['prefix' => 'auto_deposit', 'kind' => 'deposit', 'label' => 'ad_auto_deposit_amount'],
        'deposit_admin' => ['prefix' => 'admin_deposit', 'kind' => 'deposit', 'label' => 'ad_admin_deposit_amount'],
        'withdrawal_third_party' => ['prefix' => 'third_party_withdrawal', 'kind' => 'withdrawal', 'label' => 'ad_third_party_withdrawal_amount'],
        'withdrawal_auto' => ['prefix' => 'auto_withdrawal', 'kind' => 'withdrawal', 'label' => 'ad_auto_withdrawal_amount'],
        'withdrawal_admin' => ['prefix' => 'admin_withdrawal', 'kind' => 'withdrawal', 'label' => 'ad_admin_withdrawal_amount'],
    ];

    /** 匯出一次撈完的列數上限（超過就截斷，前端會提示） */
    private const int EXPORT_LIMIT = 100_000;

    /** 欄位值查詢的分批大小：一條 `whereIn` 不掛超過這個數量的 member_id */
    private const int VALUE_CHUNK = 5000;

    public function __construct(
        private AgentDashboardService $dashboard,
    ) {}

    /** 支援的 group（Request 的驗證規則直接取用，避免兩處各寫一份清單） */
    public static function groups(): array
    {
        return [
            'registration',
            'first_deposit',
            'repeat_deposit',
            'deposit',
            'withdrawal',
            ...array_keys(self::HOURLY_SOURCE_GROUPS),
            'bet',
            ...array_keys(self::COST_LABELS),
            'retention',
        ];
    }

    public function list(Agent $viewer, Agent $target, array $input): array
    {
        $group = (string) $input['group'];
        $agentIds = $this->dashboard->resolveScope($viewer, $target, $input)['target_ids'];
        [$startAt, $endAt] = $this->dashboard->range($input);
        $timezone = $this->dashboard->timezoneOf((int) $input['tz_offset']);

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = (int) ($input['per_page'] ?? 25);

        $roster = $this->roster($group, $agentIds, $startAt, $endAt, $input);

        if (!empty($input['username'])) {
            $roster->where('m.username', $input['username']);
        }

        $total = (clone $roster)->count();

        // 先分頁：只取當頁的 member_id（以 id 排序，分頁才穩定）
        $list = $this->rows(
            (clone $roster)->orderBy('m.id')->forPage($page, $perPage),
            $group,
            $startAt,
            $endAt,
            $input,
            $timezone,
        );

        return [
            'group' => $group,
            'columns' => $this->columns($group),
            'list' => $list,
            'time_basis' => $timezone,
            'page_summary' => $this->summarize($group, $list),
            'total_summary' => $this->totalSummary($group, $roster, $startAt, $endAt, $input),
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * 匯出用：一次撈完（上限 EXPORT_LIMIT），**不算總筆數、不做加總**。
     *
     * 匯出檔只要資料列，`list()` 那三樣（`count()`、分頁、`totalSummary()` 為了加總再把整份
     * 名單撈回 PHP）等於把同一份名單查詢跑三次；投注明細在全站 × 整月光這部分就 15 秒起跳。
     * 這裡只跑名單一次 ＋ 欄位值一次。
     */
    public function export(Agent $viewer, Agent $target, array $input): array
    {
        $group = (string) $input['group'];
        $agentIds = $this->dashboard->resolveScope($viewer, $target, $input)['target_ids'];
        [$startAt, $endAt] = $this->dashboard->range($input);
        $timezone = $this->dashboard->timezoneOf((int) $input['tz_offset']);

        $roster = $this->roster($group, $agentIds, $startAt, $endAt, $input);

        if (!empty($input['username'])) {
            $roster->where('m.username', $input['username']);
        }

        return [
            'group' => $group,
            'columns' => $this->columns($group),
            'list' => $this->rows(
                $roster->orderBy('m.id')->limit(self::EXPORT_LIMIT),
                $group,
                $startAt,
                $endAt,
                $input,
                $timezone,
            ),
            // 回上限讓前端判斷有沒有被截斷（沒有 count，只能比列數）
            'limit' => self::EXPORT_LIMIT,
            'time_basis' => $timezone,
        ];
    }

    /**
     * 取名單的一段（分頁或匯出上限）並補上各組欄位值。
     *
     * `$roster` 進來時 limit / offset 已套好。欄位值以 `VALUE_CHUNK` 分批查，避免匯出時
     * 一條 `whereIn` 掛著十萬個 id。
     */
    private function rows(Builder $roster, string $group, string $startAt, string $endAt, array $input, string $timezone): array
    {
        $members = $roster->get(['m.id as member_id', 'm.username', 'm.created_at', 'a.username as agent_username', 'a.hierarchy_path']);

        $memberIds = $members->pluck('member_id')->map(fn ($id) => (int) $id)->all();

        $extra = [];

        foreach (array_chunk($memberIds, self::VALUE_CHUNK) as $chunk) {
            // 各組回的都是 memberId ⇒ 欄位值，批次之間 id 不重疊，故直接以 key 聯集
            $extra += $this->columnValues($group, $chunk, $startAt, $endAt, $input);
        }

        return $members->map(function ($member) use ($extra, $group, $timezone) {
            $row = [
                'member_id' => (int) $member->member_id,
                'username' => $member->username,
                'agent_username' => $member->agent_username,
                'hierarchy' => $this->dashboard->hierarchyLevel($member->hierarchy_path),
                'registered_at' => $this->localTime($member->created_at, $timezone),
            ];

            foreach ($this->columns($group) as $column) {
                $value = $extra[(int) $member->member_id][$column['key']] ?? null;
                $row[$column['key']] = match ($column['type']) {
                    'datetime' => $this->localTime($value, $timezone),
                    'money' => Common::numAdd('0', (string) ($value ?? '0'), 4),
                    'int' => (int) $value,
                    'bool' => (bool) $value,
                    default => $value,
                };
            }

            return $row;
        })->all();
    }

    /** 各組的欄位定義（六項成本共用同一組欄位，只有金額欄名不同） */
    private function columns(string $group): array
    {
        if (isset(self::COST_LABELS[$group])) {
            return [['key' => 'amount', 'label_key' => self::COST_LABELS[$group], 'type' => 'money']];
        }

        if (isset(self::HOURLY_SOURCE_GROUPS[$group])) {
            $source = self::HOURLY_SOURCE_GROUPS[$group];

            return [
                ['key' => 'first_hour', 'label_key' => "ad_col_first_{$source['kind']}_hour", 'type' => 'datetime'],
                ['key' => 'count', 'label_key' => 'ad_col_count', 'type' => 'int'],
                ['key' => 'amount', 'label_key' => $source['label'], 'type' => 'money'],
            ];
        }

        return self::COLUMNS[$group];
    }

    /**
     * 名單：這張明細列出哪些會員。同組指標名單一致，故列數＝該組人數卡的數字。
     *
     * 一律 join `agents`（帶出所屬代理與層級）並以 `m.parent` 限定查詢對象那條線。
     */
    private function roster(string $group, array|Closure|null $agentIds, string $startAt, string $endAt, array $input): Builder
    {
        $query = DB::table('members as m')
            ->join('agents as a', 'a.id', '=', 'm.parent');

        // null＝不限制（平台端）；id 清單過長時 resolveScope 會改給 hierarchy 子查詢 Closure，
        // 兩種形態 whereIn 都吃——巨型字面 IN 會讓 MySQL 放棄時間索引而逾時
        if ($agentIds !== null) {
            $query->whereIn('m.parent', $agentIds);
        }

        return match (true) {
            // 註冊與留存同名單：期間內註冊的會員
            in_array($group, ['registration', 'retention'], true) => $query
                ->whereBetween('m.created_at', [$startAt, $endAt]),

            // 漏斗子集：首充＝期間內註冊且期間內首充；複充再往下收一層
            $group === 'first_deposit' => $query
                ->whereBetween('m.created_at', [$startAt, $endAt])
                ->whereIn('m.id', $this->firstDepositMemberIds($startAt, $endAt)),

            $group === 'repeat_deposit' => $query
                ->whereBetween('m.created_at', [$startAt, $endAt])
                ->whereIn('m.id', $this->repeatDepositMemberIds($startAt, $endAt)),

            $group === 'deposit' => $query
                ->whereIn('m.id', $this->hourlyMemberIds('deposit_count', $startAt, $endAt)),

            $group === 'withdrawal' => $query
                ->whereIn('m.id', $this->hourlyMemberIds('withdrawal_count', $startAt, $endAt)),

            isset(self::HOURLY_SOURCE_GROUPS[$group]) => $query
                ->whereIn('m.id', $this->hourlyMemberIds(self::HOURLY_SOURCE_GROUPS[$group]['prefix'] . '_count', $startAt, $endAt)),

            $group === 'bet' => $query
                ->whereIn('m.id', $this->betMemberIds($startAt, $endAt)),

            $group === 'cost_vendor_fee' => $query
                ->whereIn('m.id', $this->vendorFeeMemberIds($startAt, $endAt)),

            // 活動成本＝活動獎勵（交易表＋多錢包）＋活動 V2 成本（event_id 7），名單取三者聯集
            $group === 'cost_event' => $query->where(function (Builder $where) use ($group, $startAt, $endAt) {
                $where->whereIn('m.id', $this->costMemberIds($group, $startAt, $endAt));

                foreach ($this->eventRewardSources() as [$table, $types]) {
                    $where->orWhereIn('m.id', $this->eventRewardMemberIds($table, $types, $startAt, $endAt));
                }
            }),

            // 返水：三層代與無限代兩張會員層原始表的聯集（代理層表無會員，不列）
            $group === 'cost_rebate' => $query->where(function (Builder $where) use ($startAt, $endAt) {
                $where->whereIn('m.id', $this->threeHierarchyMemberIds($startAt, $endAt))
                    ->orWhereIn('m.id', $this->unlimitMemberIds($startAt, $endAt));
            }),

            $group === 'cost_recycle' => $query
                ->whereIn('m.id', $this->recycleMemberIds($startAt, $endAt)),

            default => $query
                ->whereIn('m.id', $this->costMemberIds($group, $startAt, $endAt)),
        };
    }

    /** 期間內有三層代分潤（會員層）的會員；篩選逐字沿用 newsr11 -5 與一階 rebateByAgent() */
    private function threeHierarchyMemberIds(string $startAt, string $endAt): Builder
    {
        return DB::table('three_hierarchy_bonus_report')
            ->where('reward_type', 1)
            ->whereRaw('(rebate + down_rebate + down2_rebate) > 0')
            ->whereBetween('created_at', [$startAt, $endAt])
            ->distinct()
            ->select('member_id');
    }

    /** 期間內有無限代獎勵（會員層）的會員；`TRUNCATE(bonus_direct, 2)` 沿用 newsr11 -2 */
    private function unlimitMemberIds(string $startAt, string $endAt): Builder
    {
        return DB::table('unlimit_bonusreport')
            ->whereRaw('TRUNCATE(bonus_direct, 2) > 0')
            ->whereBetween('created_at', [$startAt, $endAt])
            ->distinct()
            ->select('member_id');
    }

    /** 期間內有活動 V2 錢包回收的會員（經 event_v2_user_joins 歸戶） */
    private function recycleMemberIds(string $startAt, string $endAt): Builder
    {
        return DB::table('event_v2_recycles as r')
            ->join('event_v2_user_joins as j', 'j.id', '=', 'r.event_v2_user_join_id')
            ->whereBetween('r.created_at', [$startAt, $endAt])
            ->distinct()
            ->select('j.member_id');
    }

    /** 期間內首充的會員（全歷史最早成功付款落在期間內） */
    private function firstDepositMemberIds(string $startAt, string $endAt): Builder
    {
        return DB::query()
            ->fromSub($this->firstPayQuery($startAt, $endAt), 'fp')
            ->whereBetween('fp.first_pay', [$startAt, $endAt])
            ->select('fp.member_id');
    }

    /** 期間內首充且又複充的會員 */
    private function repeatDepositMemberIds(string $startAt, string $endAt): Builder
    {
        return DB::table('orders as o')
            ->joinSub($this->firstPayQuery($startAt, $endAt), 'fp', 'fp.member_id', '=', 'o.member_id')
            ->where('o.status', OrderStatusEnum::DEPOSIT_FINISHED->value)
            ->whereBetween('o.pay_time', [$startAt, $endAt])
            ->whereBetween('fp.first_pay', [$startAt, $endAt])
            ->whereColumn('o.pay_time', '>', 'fp.first_pay')
            ->distinct()
            ->select('o.member_id');
    }

    /**
     * 每位會員全歷史最早的成功付款時間。
     *
     * 候選先收斂成「期間內有成功充值的會員」，再對候選算全歷史 MIN，避免全表 GROUP BY
     * （與一階 `depositOrderByAgent()` 同一套作法）。
     */
    private function firstPayQuery(string $startAt, string $endAt, ?array $memberIds = null): Builder
    {
        $success = OrderStatusEnum::DEPOSIT_FINISHED->value;

        $query = DB::table('orders')
            ->select('member_id', DB::raw('MIN(pay_time) as first_pay'))
            ->where('status', $success)
            ->groupBy('member_id');

        // 候選收斂：已知會員清單就直接用；否則取「期間內註冊」的會員——漏斗子集只算這批人，
        // 用「全站期間內有充值的會員」當候選會白掃幾萬人（TPower 實測差 70 倍）
        return $memberIds === null
            ? $query->whereIn(
                'member_id',
                DB::table('members')->select('id')->whereBetween('created_at', [$startAt, $endAt]),
            )
            : $query->whereIn('member_id', $memberIds);
    }

    /** 期間內有成功充值 / 提現的會員（走整點桶彙總，成功狀態已固化在寫入端） */
    private function hourlyMemberIds(string $column, string $startAt, string $endAt): Builder
    {
        return DB::table('member_hourly_statistics')
            ->whereBetween('summary_hour', [Carbon::parse($startAt)->startOfHour()->toDateTimeString(), $endAt])
            ->groupBy('member_id')
            ->havingRaw("SUM({$column}) > 0")
            ->select('member_id');
    }

    /** 期間內有注單彙總的會員 */
    private function betMemberIds(string $startAt, string $endAt): Builder
    {
        return DB::table('summary_bet_records')
            ->whereBetween('summary_time', [$startAt, $endAt])
            ->whereNotIn('vendor_id', SummaryBetRecord::reportExcludedVendorIds())
            ->distinct()
            ->select('member_id');
    }

    /** 期間內有遊戲商盤費的會員 */
    private function vendorFeeMemberIds(string $startAt, string $endAt): Builder
    {
        return DB::table('summary_bet_records')
            ->whereBetween('summary_time', [$startAt, $endAt])
            ->whereNotIn('vendor_id', SummaryBetRecord::reportExcludedVendorIds())
            ->groupBy('member_id')
            ->havingRaw('SUM(cost) <> 0')
            ->select('member_id');
    }

    /** 活動獎勵的來源表與交易類型（與一階同一份口徑） */
    private function eventRewardSources(): array
    {
        return [
            ['transaction_logs', AgentDashboardService::eventRewardTxTypes()],
            ['wallet_transaction_logs', AgentDashboardService::eventRewardWalletTxTypes()],
        ];
    }

    /** 期間內領過活動獎勵的會員 */
    private function eventRewardMemberIds(string $table, array $types, string $startAt, string $endAt): Builder
    {
        return DB::table($table)
            ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
            ->whereIn('type', $types)
            ->whereBetween('created_at', [$startAt, $endAt])
            ->groupBy('member_id')
            ->havingRaw('SUM(point) <> 0')
            ->select('member_id');
    }

    /** 期間內有該項成本的會員 */
    private function costMemberIds(string $group, string $startAt, string $endAt): Builder
    {
        return DB::table('summary_event_records')
            ->whereIn('event_id', self::COST_EVENT_IDS[$group])
            ->whereBetween('summary_time', [$startAt, $endAt])
            ->groupBy('member_id')
            ->havingRaw('SUM(point) <> 0')
            ->select('member_id');
    }

    /**
     * 當頁會員的欄位值：memberId ⇒ [欄位 ⇒ 值]。
     *
     * @param  array<int, int>  $memberIds
     */
    private function columnValues(string $group, array $memberIds, string $startAt, string $endAt, array $input): array
    {
        return match (true) {
            $group === 'registration' => $this->firstDepositValues($memberIds, $startAt, $endAt, withAmount: false),
            $group === 'first_deposit' => $this->firstDepositValues($memberIds, $startAt, $endAt, withAmount: true),
            $group === 'repeat_deposit' => $this->repeatDepositValues($memberIds, $startAt, $endAt),
            $group === 'deposit' => $this->hourlyValues($memberIds, $startAt, $endAt, 'deposit'),
            $group === 'withdrawal' => $this->hourlyValues($memberIds, $startAt, $endAt, 'withdrawal'),
            isset(self::HOURLY_SOURCE_GROUPS[$group]) => $this->hourlyValues(
                $memberIds,
                $startAt,
                $endAt,
                self::HOURLY_SOURCE_GROUPS[$group]['prefix'],
                ['first_hour', 'count', 'amount'],
            ),
            $group === 'bet' => $this->betValues($memberIds, $startAt, $endAt),
            $group === 'retention' => $this->retentionValues($memberIds, $startAt, $endAt, (int) $input['tz_offset'], (int) $input['retention_day']),
            $group === 'cost_vendor_fee' => $this->vendorFeeValues($memberIds, $startAt, $endAt),
            $group === 'cost_event' => $this->eventCostValues($memberIds, $startAt, $endAt),
            $group === 'cost_rebate' => $this->rebateValues($memberIds, $startAt, $endAt),
            $group === 'cost_recycle' => $this->recycleValues($memberIds, $startAt, $endAt),
            default => $this->costValues($group, $memberIds, $startAt, $endAt),
        };
    }

    /** 首充時間／金額，並標記是否複充（① 與 ② 的布林欄） */
    private function firstDepositValues(array $memberIds, string $startAt, string $endAt, bool $withAmount): array
    {
        $rows = DB::table('orders as o')
            ->joinSub($this->firstPayQuery($startAt, $endAt, $memberIds), 'fp', 'fp.member_id', '=', 'o.member_id')
            ->where('o.status', OrderStatusEnum::DEPOSIT_FINISHED->value)
            ->whereBetween('o.pay_time', [$startAt, $endAt])
            ->whereIn('o.member_id', $memberIds)
            ->groupBy('o.member_id')
            ->select(
                'o.member_id',
                DB::raw('MIN(fp.first_pay) as first_pay'),
                DB::raw('SUM(CASE WHEN o.pay_time = fp.first_pay THEN o.point ELSE 0 END) as first_amount'),
                DB::raw('SUM(CASE WHEN o.pay_time > fp.first_pay THEN 1 ELSE 0 END) as repeat_count'),
            )
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $memberId = (int) $row->member_id;
            $inPeriod = $row->first_pay >= $startAt && $row->first_pay <= $endAt;
            $result[$memberId] = [
                'first_deposit_at' => $inPeriod ? $row->first_pay : null,
                'is_first_deposit' => $inPeriod,
                'is_repeat_deposit' => (int) $row->repeat_count > 0,
            ];

            if ($withAmount) {
                $result[$memberId]['first_deposit_amount'] = $row->first_amount;
            }
        }

        return $result;
    }

    /** 複充筆數／金額（首充之後的成功充值） */
    private function repeatDepositValues(array $memberIds, string $startAt, string $endAt): array
    {
        return DB::table('orders as o')
            ->joinSub($this->firstPayQuery($startAt, $endAt, $memberIds), 'fp', 'fp.member_id', '=', 'o.member_id')
            ->where('o.status', OrderStatusEnum::DEPOSIT_FINISHED->value)
            ->whereBetween('o.pay_time', [$startAt, $endAt])
            ->whereIn('o.member_id', $memberIds)
            ->whereColumn('o.pay_time', '>', 'fp.first_pay')
            ->groupBy('o.member_id')
            ->select(
                'o.member_id',
                DB::raw('MIN(fp.first_pay) as first_deposit_at'),
                DB::raw('COUNT(*) as repeat_deposit_count'),
                DB::raw('SUM(o.point) as repeat_deposit_amount'),
            )
            ->get()
            ->keyBy('member_id')
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * 充值 / 提現（含六類來源明細）：首次發生的整點桶、筆數、金額。
     *
     * @param  string  $prefix  `member_hourly_statistics` 欄位前綴（deposit / withdrawal / third_party_deposit …）
     * @param  array{0: string, 1: string, 2: string}|null  $keys  輸出鍵 [首次時間, 筆數, 金額]；預設沿用總充提的鍵名
     */
    private function hourlyValues(array $memberIds, string $startAt, string $endAt, string $prefix, ?array $keys = null): array
    {
        $countColumn = $prefix . '_count';
        $amountColumn = $prefix . '_amount';
        [$firstKey, $countKey, $amountKey] = $keys ?? ["first_{$prefix}_hour", $countColumn, $amountColumn];

        $rows = DB::table('member_hourly_statistics')
            ->whereBetween('summary_hour', [Carbon::parse($startAt)->startOfHour()->toDateTimeString(), $endAt])
            ->whereIn('member_id', $memberIds)
            ->groupBy('member_id')
            ->select(
                'member_id',
                DB::raw("MIN(CASE WHEN {$countColumn} > 0 THEN summary_hour END) as first_hour"),
                DB::raw("SUM({$countColumn}) as cnt"),
                DB::raw("SUM({$amountColumn}) as amount"),
            )
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->member_id] = [
                $firstKey => $row->first_hour,
                $countKey => $row->cnt,
                $amountKey => $row->amount,
            ];
        }

        return $result;
    }

    /** 投注：首注整點桶、筆數、投注／派獎／GGR */
    private function betValues(array $memberIds, string $startAt, string $endAt): array
    {
        $rows = DB::table('summary_bet_records')
            ->whereBetween('summary_time', [$startAt, $endAt])
            ->whereNotIn('vendor_id', SummaryBetRecord::reportExcludedVendorIds())
            ->whereIn('member_id', $memberIds)
            ->groupBy('member_id')
            ->select(
                'member_id',
                // 有注單即算（`bet` 是有效投注，0 是正常值），與卡片的真金投注人數同口徑
                DB::raw('MIN(summary_time) as first_bet_hour'),
                DB::raw('SUM(record_count) as bet_count'),
                DB::raw('SUM(bet) as bet_amount'),
                DB::raw('SUM(win_lose) as win_lose'),
            )
            ->get();

        $result = [];

        foreach ($rows as $row) {
            // 派獎 = 投注 + 會員淨輸贏；GGR = −會員淨輸贏（與一階同口徑）
            $bet = Common::numAdd('0', (string) $row->bet_amount, 4);
            $winLose = Common::numAdd('0', (string) $row->win_lose, 4);

            $result[(int) $row->member_id] = [
                'first_bet_hour' => $row->first_bet_hour,
                'bet_count' => $row->bet_count,
                'bet_amount' => $bet,
                'payout_amount' => Common::numAdd($bet, $winLose, 4),
                'ggr' => Common::numMul($winLose, '-1', 4),
            ];
        }

        return $result;
    }

    /** 事件成本各組：期間內該會員的成本金額 */
    private function costValues(string $group, array $memberIds, string $startAt, string $endAt): array
    {
        return DB::table('summary_event_records')
            ->whereIn('event_id', self::COST_EVENT_IDS[$group])
            ->whereBetween('summary_time', [$startAt, $endAt])
            ->whereIn('member_id', $memberIds)
            ->groupBy('member_id')
            ->select('member_id', DB::raw('SUM(point) as amount'))
            ->get()
            ->keyBy('member_id')
            ->map(fn ($row) => ['amount' => $row->amount])
            ->all();
    }

    /**
     * 活動成本：活動獎勵（交易表＋多錢包）＋活動 V2 成本（`summary_event_records` event_id 7）。
     *
     * 與一階指標卡同一組來源，明細加總才會等於卡片數字。
     */
    private function eventCostValues(array $memberIds, string $startAt, string $endAt): array
    {
        $result = $this->costValues('cost_event', $memberIds, $startAt, $endAt);

        foreach ($this->eventRewardSources() as [$table, $types]) {
            $rows = DB::table($table)
                ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
                ->whereIn('type', $types)
                ->whereBetween('created_at', [$startAt, $endAt])
                ->whereIn('member_id', $memberIds)
                ->groupBy('member_id')
                ->select('member_id', DB::raw('SUM(point) as amount'))
                ->get();

            foreach ($rows as $row) {
                $memberId = (int) $row->member_id;
                $result[$memberId]['amount'] = Common::numAdd(
                    (string) ($result[$memberId]['amount'] ?? '0'),
                    Common::numAdd('0', (string) $row->amount, 4),
                    4,
                );
            }
        }

        return $result;
    }

    /**
     * 返水成本（會員層）：三層代分潤 ＋ 無限代獎勵發給該會員的金額，篩選與一階 `rebateByAgent()` 的會員層來源相同。
     *
     * 代理本人份（`*_agents` 表）沒有會員可歸戶，不在此列 ⇒ 明細加總會小於卡片數字。
     */
    private function rebateValues(array $memberIds, string $startAt, string $endAt): array
    {
        $sources = [
            DB::table('three_hierarchy_bonus_report')
                ->where('reward_type', 1)
                ->whereRaw('(rebate + down_rebate + down2_rebate) > 0')
                ->whereBetween('created_at', [$startAt, $endAt])
                ->whereIn('member_id', $memberIds)
                ->groupBy('member_id')
                ->select('member_id', DB::raw('SUM(rebate + down_rebate + down2_rebate) as amount')),
            DB::table('unlimit_bonusreport')
                ->whereRaw('TRUNCATE(bonus_direct, 2) > 0')
                ->whereBetween('created_at', [$startAt, $endAt])
                ->whereIn('member_id', $memberIds)
                ->groupBy('member_id')
                ->select('member_id', DB::raw('SUM(TRUNCATE(bonus_direct, 2)) as amount')),
        ];

        $result = [];

        foreach ($sources as $query) {
            foreach ($query->get() as $row) {
                $memberId = (int) $row->member_id;
                $result[$memberId] = ['amount' => Common::numAdd(
                    $result[$memberId]['amount'] ?? '0',
                    Common::numAdd('0', (string) $row->amount, 4),
                    4,
                )];
            }
        }

        return $result;
    }

    /** 回收金額：活動 V2 錢包剩餘回收，經 event_v2_user_joins 歸戶到會員（與一階 `recycleByAgent()` 同源） */
    private function recycleValues(array $memberIds, string $startAt, string $endAt): array
    {
        return DB::table('event_v2_recycles as r')
            ->join('event_v2_user_joins as j', 'j.id', '=', 'r.event_v2_user_join_id')
            ->whereBetween('r.created_at', [$startAt, $endAt])
            ->whereIn('j.member_id', $memberIds)
            ->groupBy('j.member_id')
            ->select('j.member_id', DB::raw('SUM(r.recycle_amount) as amount'))
            ->get()
            ->keyBy('member_id')
            ->map(fn ($row) => ['amount' => $row->amount])
            ->all();
    }

    /** 遊戲商盤費（來自注單彙總的 cost 欄） */
    private function vendorFeeValues(array $memberIds, string $startAt, string $endAt): array
    {
        return DB::table('summary_bet_records')
            ->whereBetween('summary_time', [$startAt, $endAt])
            ->whereNotIn('vendor_id', SummaryBetRecord::reportExcludedVendorIds())
            ->whereIn('member_id', $memberIds)
            ->groupBy('member_id')
            ->select('member_id', DB::raw('SUM(cost) as amount'))
            ->get()
            ->keyBy('member_id')
            ->map(fn ($row) => ['amount' => $row->amount])
            ->all();
    }

    /**
     * 留存：D 日「當天」首次登入／投注／充值的時間，未回訪為 null。
     *
     * 日界一律帶使用者時區偏移（與一階留存矩陣同一條規則）。
     */
    private function retentionValues(array $memberIds, string $startAt, string $endAt, int $tz, int $day): array
    {
        $behaviorStart = Carbon::parse($startAt)->subDay()->toDateTimeString();
        $behaviorEnd = Carbon::parse($endAt)->addDays($day + 1)->toDateTimeString();

        $sources = [
            'login_returned_at' => [
                'table' => 'member_login_logs',
                'time' => 'created_at',
                'filter' => fn (Builder $q) => $q->where('b.status', 1),
            ],
            'bet_returned_at' => [
                'table' => 'summary_bet_records',
                'time' => 'summary_time',
                // 有注單即算回訪，與一階留存矩陣同口徑（同樣排除 env 清單內的遊戲商）
                'filter' => fn (Builder $q) => $q->whereNotIn('b.vendor_id', SummaryBetRecord::reportExcludedVendorIds()),
            ],
            'deposit_returned_at' => [
                'table' => 'member_hourly_statistics',
                'time' => 'summary_hour',
                'filter' => fn (Builder $q) => $q->where('b.deposit_count', '>', 0),
            ],
        ];

        $result = [];

        foreach ($sources as $key => $source) {
            $query = DB::table($source['table'] . ' as b')
                ->join('members as m', 'm.id', '=', 'b.member_id')
                ->whereIn('b.member_id', $memberIds)
                ->whereBetween("b.{$source['time']}", [$behaviorStart, $behaviorEnd])
                ->whereRaw(
                    "DATEDIFF(DATE(DATE_ADD(b.{$source['time']}, INTERVAL ? HOUR)), DATE(DATE_ADD(m.created_at, INTERVAL ? HOUR))) = ?",
                    [$tz, $tz, $day],
                )
                ->groupBy('b.member_id')
                ->select('b.member_id', DB::raw("MIN(b.{$source['time']}) as returned_at"));

            ($source['filter'])($query);

            foreach ($query->get() as $row) {
                $result[(int) $row->member_id][$key] = $row->returned_at;
            }
        }

        return $result;
    }

    /** 本頁加總：金額與筆數欄相加（人數＝該欄位有值的列數，不另回） */
    private function summarize(string $group, array $list): array
    {
        $summary = [];

        foreach ($this->columns($group) as $column) {
            if ($column['type'] === 'money') {
                $summary[$column['key']] = array_reduce(
                    $list,
                    fn ($carry, $row) => Common::numAdd($carry, (string) $row[$column['key']], 4),
                    '0',
                );
            }

            if ($column['type'] === 'int') {
                $summary[$column['key']] = array_sum(array_column($list, $column['key']));
            }
        }

        return $summary;
    }

    /**
     * 全頁加總：對名單整體聚合，不撈全部列。
     *
     * 做法是把名單 query 當子查詢，只跑一次金額 / 筆數的 SUM。
     */
    private function totalSummary(string $group, Builder $roster, string $startAt, string $endAt, array $input): array
    {
        $columns = collect($this->columns($group))
            ->filter(fn ($column) => in_array($column['type'], ['money', 'int'], true));

        if ($columns->isEmpty()) {
            return [];
        }

        $memberIds = (clone $roster)->pluck('m.id')->map(fn ($id) => (int) $id)->all();

        if ($memberIds === []) {
            return $columns->mapWithKeys(fn ($column) => [
                $column['key'] => $column['type'] === 'money' ? '0.0000' : 0,
            ])->all();
        }

        $values = $this->columnValues($group, $memberIds, $startAt, $endAt, $input);

        return $columns->mapWithKeys(function ($column) use ($values) {
            if ($column['type'] === 'int') {
                return [$column['key'] => array_sum(array_column($values, $column['key']))];
            }

            $total = array_reduce(
                $values,
                fn ($carry, $row) => Common::numAdd($carry, (string) ($row[$column['key']] ?? '0'), 4),
                '0',
            );

            return [$column['key'] => $total];
        })->all();
    }

    /** DB 時間（UTC）⇒ 使用者所選時區的牆鐘；null 保持 null（未發生／未回訪） */
    private function localTime(mixed $value, string $timezone): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i:s');
    }
}
