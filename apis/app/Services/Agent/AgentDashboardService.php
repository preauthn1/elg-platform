<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Enums\OrderStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\SummaryBetRecord;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 代理數據看板（target/tickets#4180 第一階段：看板本體，不含指標明細下鑽）。
 *
 * 設計要點（完整規劃見 `docs/代理數據看板/api-plan.md`）：
 *
 * - **一次聚合、兩處使用**：所有指標查詢一律 `GROUP BY members.parent`（會員的直屬代理），
 *   得到「直屬代理 ⇒ 指標」的 map；指標卡取「查詢對象那條線」的加總、代理明細列表逐列取，
 *   貢獻佔比的分母取「登入者可視範疇」的全量加總。故一次查詢同時餵三支端點，不重複掃表。
 * - **來源表選擇**：投注／派獎／盤費走 `summary_bet_records`、事件類成本走 `summary_event_records`
 *   （返水改讀三層代／無限代原始表、回收讀 `event_v2_recycles`，見各方法說明），
 *   都是**會員粒度**的表。不用 `summary_bet_record_agents` / `summary_event_record_agents`：前者
 *   T+1 只到前一天（撐不起「今日」），後者實測會整小時漏資料；且會員粒度才能與二階明細同源。
 * - **時間基準以 `tz_offset` 為唯一來源**：輸入的 `start_time` / `end_time` 即「使用者所選時區的牆鐘」，
 *   進來以 `tz_offset` 轉 UTC 查詢；「換日」（留存的註冊批次與 D 日）用同一個偏移決定日界；
 *   輸出的時間欄亦回同一時區。全支端點只有一套時區規則，不混入固定 +8 基準。
 *   （既有報表是前端 `timezone-shift.ts` 補平移的過渡做法，端點本身不懂時區，不沿用。）
 * - **金額**：DB decimal 讀出後一律 `Common::numAdd('0', …, 4)` 正規化再參與運算。
 *
 * 🔴 **SR28 代理營運彙總表（`AgentOperationSummaryService`，target/tickets#4362）共用本類別的
 * 「直屬代理 ⇒ 指標」工具與部分指標**：`lineIds()`、`aggregateTtl()`、`applyScope()`、`finish()`、
 * `registrationByAgent()`、`costByAgent()`、`eventRewardByAgent()`、`rebateByAgent()`。
 * 改這幾支的口徑或回傳形狀，SR28 的數字會一起變，兩邊要一起看。
 *
 * @knowledge rules/payment-cost-summary
 */
class AgentDashboardService
{
    /**
     * 代理 id 清單改用 `hierarchy_path` 子查詢的門檻。
     *
     * 超過此數量的字面 IN 清單會讓 MySQL 放棄時間索引（見 `resolveScope()`）。
     * 取 500：正式站單一條線常見數十至數百個代理，落在此範圍內走 id 清單最直接；
     * 站台 root（數萬個）才需要改走子查詢。
     */
    private const int SCOPE_ID_LIMIT = 500;

    /**
     * 來自 `summary_event_records` 的成本項 ⇒ `event_id`（對照 newsr11 的「特殊平台」負值 id，`-6` ⇒ `event_id = 6`）：
     * 7 活動 V2（活動成本的一部分）、6 禮碼（newsr11 的 rollingchip 列）、9 VIP、3 代收手續費、8 代付手續費、4 簡訊。
     *
     * 代收（`payment_fee`，卡片名「支付手續費」）與代付（`withdrawal_fee`，卡片名「提款手續費」）分成兩項，
     * 禁止合併成單一支付成本：newsr11 與 newsr11y 也是兩列，合併會讓看板與報表對不起來（target/tickets#4412）。
     *
     * 不在此表的成本項另有來源：返水成本走 `rebateByAgent()`（三層代＋無限代原始表，對齊 newsr11 的
     * 「3 layers of agents mode」與「全民代理」兩列；`summary_event_records` 的 event 2 / 5 實測沒資料）、
     * 回收金額走 `recycleByAgent()`（`event_v2_recycles`，對齊 newsr11 eventCost 列的回收金額）。
     */
    private const array COST_EVENT_IDS = [
        // 活動成本的主體改抽 transaction_logs（見 EVENT_REWARD_TX_TYPES）；此處只留活動 V2 的成本
        'event' => [7],
        'rolling_chip' => [6],
        'vip' => [9],
        'payment_fee' => [3],
        'withdrawal_fee' => [8],
        'sms' => [4],
    ];

    /**
     * 活動獎勵的交易類型：對齊 newsr11 的 `-1 Reward`（API_Agent `TransactionEnum::REPORT_EVENT_TYPES`）。
     *
     * 不改用 `summary_event_records.event_id = 1`——那份彙總由 lottery SP 產出，TPower 實測有零星
     * 缺漏（2026-08-05 23:00 整個小時全站沒寫、02:00 少一筆），且它只抓 7 種 type，newsr11 抓 17 種。
     * 直接讀原始交易表才與運營在對的報表一致。
     */
    private const array EVENT_REWARD_TX_TYPES = [
        TransactionEnum::CHECKIN_REWARD->value,
        TransactionEnum::ACTIVITY_AWARD->value,
        TransactionEnum::LOGIN_EVENT_REWARDS->value,
        TransactionEnum::FIRST_DEPOSIT_REWARDS->value,
        TransactionEnum::REGISTRATION_GIFT->value,
        TransactionEnum::BIND_MOBILE_PHONE_GIFT->value,
        TransactionEnum::DEPOSIT_REWARDS->value,
        TransactionEnum::PICK_UP_ONCE_A_DAY->value,
        TransactionEnum::INVITE_PLAYER_OFFERS->value,
        TransactionEnum::W_JACKPOT_REWARD->value,
        TransactionEnum::W_JACKPOT_6D_REWARD->value,
        TransactionEnum::SLOT_BATTLE->value,
        TransactionEnum::LOSE_REBATE->value,
        TransactionEnum::JACKPOT->value,
        TransactionEnum::SHAREHOLDER_REWARD->value,
        TransactionEnum::COUPON_CODE->value,
        TransactionEnum::MISSION_REWARD->value,
    ];

    /** 多錢包活動獎勵（newsr11 的「多錢包活動獎勵」union，`wallet_transaction_logs.type = 7`） */
    private const array EVENT_REWARD_WALLET_TX_TYPES = [7];

    /** 留存觀察的 D 日 */
    private const array RETENTION_DAYS = [1, 7, 30];

    /** 活動獎勵的交易類型（二階明細共用，口徑只有一份） */
    public static function eventRewardTxTypes(): array
    {
        return self::EVENT_REWARD_TX_TYPES;
    }

    /** 多錢包活動獎勵的交易類型（二階明細共用） */
    public static function eventRewardWalletTxTypes(): array
    {
        return self::EVENT_REWARD_WALLET_TX_TYPES;
    }

    /** 指標卡：25 項非留存指標 + 貢獻佔比分母 */
    public function overview(Agent $viewer, Agent $target, array $input): array
    {
        $scope = $this->resolveScope($viewer, $target, $input);

        // 指標卡只聚合「查詢對象那條線」——正式站不能為了卡片去掃全站
        $targetMetrics = $this->foldMetrics($this->aggregate($scope['target_ids'], $input, $scope['target_signature']), null);

        return [
            'target' => $targetMetrics,
            'scope_total' => $this->scopeTotal($scope, $input, $targetMetrics),
        ];
    }

    /**
     * 貢獻佔比的分母：可視範疇的同期總計（單列聚合，不分組）。
     *
     * 兩個減法讓正式站跑得動：
     * 1. 查詢對象就等於可視範疇時（平台端沒指定代理帳號＝全站對全站），佔比恆為 100%，
     *    直接沿用卡片的數字，不再多跑一輪全站聚合。
     * 2. 其餘情況由 `aggregate()` 內建的結果快取承接（同一組條件會被反覆查：切成本開關、
     *    翻頁、開明細都可能重打）；此處不再另設一層——fold 只是純 PHP 加總，快取它沒有意義。
     */
    private function scopeTotal(array $scope, array $input, array $targetMetrics): array
    {
        if ($scope['target_is_scope']) {
            return $targetMetrics;
        }

        return $this->foldMetrics(
            $this->aggregate($scope['scope_ids'], $input, $scope['scope_signature'], grouped: false),
            null,
        );
    }

    /**
     * 留存矩陣：以「註冊批次」為分母，往後看 D1 / D7 / D30 當天是否發生對應行為。
     *
     * 未到期批次照算（分母不縮小、命中數為 0），故查「近 7 天」時 D30 通常整排是 0。
     */
    public function retention(Agent $viewer, Agent $target, array $input): array
    {
        $scope = $this->resolveScope($viewer, $target, $input);
        $ids = $scope['target_ids'];
        [$startAt, $endAt] = $this->range($input);
        $tz = (int) $input['tz_offset'];

        // 行為時間的上下界：讓索引先收斂範圍，再由 DATEDIFF 判定是否落在 D 日當天。
        // 下界往前一天、上界往後 31 天，涵蓋 D1〜D30 與時區平移的邊界。
        $behaviorStart = Carbon::parse($startAt)->subDay()->toDateTimeString();
        $behaviorEnd = Carbon::parse($endAt)->addDays(31)->toDateTimeString();

        $denominator = (int) $this->applyScope(
            DB::table('members')->whereBetween('created_at', [$startAt, $endAt]),
            'parent',
            $ids,
        )->count();

        return [
            'denominator' => $denominator,
            'matrix' => [
                'login' => $this->retentionRow(
                    DB::table('members as m')
                        ->join('member_login_logs as b', function ($join) {
                            $join->on('b.member_id', '=', 'm.id')->where('b.status', 1);
                        })
                        ->whereBetween('b.created_at', [$behaviorStart, $behaviorEnd]),
                    'b.created_at',
                    $ids,
                    $startAt,
                    $endAt,
                    $tz,
                ),
                'bet' => $this->retentionRow(
                    // 有注單即算回訪，與真金投注人數同口徑（不要求有效投注 > 0）
                    DB::table('members as m')
                        ->join('summary_bet_records as b', 'b.member_id', '=', 'm.id')
                        ->whereBetween('b.summary_time', [$behaviorStart, $behaviorEnd])
                        ->whereNotIn('b.vendor_id', SummaryBetRecord::reportExcludedVendorIds()),
                    'b.summary_time',
                    $ids,
                    $startAt,
                    $endAt,
                    $tz,
                ),
                'deposit' => $this->retentionRow(
                    DB::table('members as m')
                        ->join('member_hourly_statistics as b', function ($join) {
                            $join->on('b.member_id', '=', 'm.id')->where('b.deposit_count', '>', 0);
                        })
                        ->whereBetween('b.summary_hour', [$behaviorStart, $behaviorEnd]),
                    'b.summary_hour',
                    $ids,
                    $startAt,
                    $endAt,
                    $tz,
                ),
            ],
        ];
    }

    /**
     * 代理明細列表：逐層展開，一次只回一層（同一層皆為兄弟節點，故加總無父子重複計算）。
     *
     * `page_summary` ＝當頁列相加，`total_summary` ＝當層全部頁相加（不是整條線總計——
     * 整條線的數字在 overview，混在同一張表裡只有一頁時會看起來像兩個不一致的加總）。
     */
    public function agentList(Agent $viewer, Agent $target, array $input): array
    {
        $scope = $this->resolveScope($viewer, $target, $input);
        $agents = $scope['agents'];
        // 列出的是下級，故聚合整條線；scope 只決定每列顯示「線」還是「自身」的數字
        $maps = $this->aggregate($scope['line_ids'], $input, $scope['line_signature']);
        $isLine = ($input['scope'] ?? 'line') === 'line';

        $parentId = (int) ($input['parent_agent_id'] ?? $target->id);
        if ($parentId !== (int) $target->id && !$viewer->canSeeAgent($parentId)) {
            $parentId = (int) $target->id;
        }

        // 期間內完全沒有數據的代理不列出（含分頁與加總）；為免藏掉「自身 0、下級有資料」的
        // 代理而讓展開路徑斷掉，判定用「自身或其下級任一有資料」
        $withData = $this->agentIdsWithData($maps, $agents);

        $rows = $agents
            ->where('parent', $parentId)
            ->filter(fn ($row) => isset($withData[$row->id]))
            ->sortBy('id')
            ->values();
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = (int) ($input['per_page'] ?? 10);
        $pageRows = $rows->forPage($page, $perPage)->values();

        // 一列的彙總範圍：line 模式取該代理整條線，self 模式只取自身
        $idsOf = fn (Collection $subset) => $subset
            ->flatMap(fn ($row) => $isLine ? $this->lineIds($agents, $row) : [(int) $row->id])
            ->unique()
            ->all();

        $list = $pageRows->map(function ($row) use ($agents, $maps, $isLine, $withData) {
            $ids = $isLine ? $this->lineIds($agents, $row) : [(int) $row->id];

            return [
                'agent_id' => (int) $row->id,
                'username' => $row->username,
                'nickname' => $row->nickname,
                'parent_username' => $agents->get($row->parent)->username ?? null,
                'hierarchy' => $this->hierarchyLevel($row->hierarchy_path),
                'has_children' => $agents->contains(
                    fn ($agent) => $agent->parent === (int) $row->id && isset($withData[$agent->id]),
                ),
                'metrics' => $this->foldMetrics($maps, $ids),
            ];
        })->all();

        return [
            'list' => $list,
            'page_summary' => $this->foldMetrics($maps, $idsOf($pageRows)),
            'total_summary' => $this->foldMetrics($maps, $idsOf($rows)),
            'pagination' => [
                'total' => $rows->count(),
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
            ],
        ];
    }

    /**
     * 查詢對象：未指定代理帳號時為登入者本人（子帳號以主帳號為準）。
     *
     * 指定帳號時必須落在登入者可視範疇內（`canSeeAgent()` fail-closed），否則回 null 由
     * 呼叫端回錯誤——代理只能查自己與自己下線。
     */
    public function resolveTargetAgent(Agent $viewer, ?string $username): ?Agent
    {
        if ($username === null || $username === '') {
            return $this->ownerAgent($viewer);
        }

        $target = Agent::where('username', $username)->first();

        if ($target === null || !$viewer->canSeeAgent((int) $target->id)) {
            return null;
        }

        return $target;
    }

    /** 子帳號（`host_id != 0`）的範疇以其主帳號為準 */
    private function ownerAgent(Agent $viewer): Agent
    {
        if ($viewer->host_id != 0) {
            return Agent::find($viewer->host_id) ?? $viewer;
        }

        return $viewer;
    }

    /**
     * 範疇：登入者可視的全部代理（`scope_ids`，貢獻佔比分母用）與查詢對象那條線（`target_ids`）。
     *
     * 三個 id 欄位皆為 `whereIn` 的第二參數，有三種形態：
     * - **`null`**：不限制（平台端登入、且查詢對象就是平台端自己）。
     * - **`array`**：代理數在 `SCOPE_ID_LIMIT` 以內，直接以 id 清單比對。
     * - **`Closure`**：代理數超過上限，改以 `hierarchy_path` 前綴子查詢（見 `scopeSubquery()`）。
     *   巨型 IN 清單會讓 MySQL 放棄時間索引而逾時——站台 root 代理（線下數萬個代理）
     *   就是這個情形，實測 44,663 個 id 會讓查詢從 55ms 惡化到 44 秒（500 逾時）。
     *
     * 二階明細（`AgentDashboardDetailService`）直接複用本方法，範疇規則只有一份。
     *
     * `line_ids` 是查詢對象那條線的全部代理（不受 `scope` 影響）——代理明細列表列的是下級，
     * 聚合範圍必須涵蓋整條線，`scope` 只決定每一列顯示「線」還是「自身」的數字。
     *
     * 三個 `*_signature` 是對應範疇的穩定識別，作為 `aggregate()` 結果快取的鍵成分——
     * 範疇欄位可能是 Closure（無法序列化）或數萬筆 id 陣列（不宜入鍵）。
     * `target_is_scope`（查詢對象等於可視範疇）同理不直接比對 id 清單，改比集合。
     *
     * @return array{agents: Collection, scope_ids: array<int, int>|Closure|null, target_ids: array<int, int>|Closure|null, line_ids: array<int, int>|Closure|null, scope_signature: string, target_signature: string, line_signature: string, target_is_scope: bool}
     */
    public function resolveScope(Agent $viewer, Agent $target, array $input): array
    {
        $agents = $this->scopeAgents($this->ownerAgent($viewer));
        $targetRow = $agents->get((int) $target->id);

        $lineIds = $targetRow === null ? [(int) $target->id] : $this->lineIds($agents, $targetRow);

        $isLineScope = ($input['scope'] ?? 'line') === 'line';
        $targetIds = $isLineScope ? $lineIds : [(int) $target->id];

        $owner = $this->ownerAgent($viewer);
        $unrestricted = $owner->isPlatformAdmin();

        // 查詢對象就是平台端自己（未指定代理帳號）＋線模式 ⇒ 等同全站，不加條件
        $targetIsWholeSite = $unrestricted && $target->isPlatformAdmin() && $isLineScope;

        $scopeIds = $unrestricted ? null : $agents->keys()->map(fn ($id) => (int) $id)->all();

        [$scopeFilter, $scopeSignature] = $this->scopeFilter($scopeIds, $owner);
        [$targetFilter, $targetSignature] = $targetIsWholeSite
            ? [null, 'all']
            : $this->scopeFilter($targetIds, $isLineScope ? $target : null);
        [$lineFilter, $lineSignature] = $unrestricted && $target->isPlatformAdmin()
            ? [null, 'all']
            : $this->scopeFilter($lineIds, $target);

        return [
            'agents' => $agents,
            'scope_ids' => $scopeFilter,
            'target_ids' => $targetFilter,
            'line_ids' => $lineFilter,
            'scope_signature' => $scopeSignature,
            'target_signature' => $targetSignature,
            'line_signature' => $lineSignature,
            // 集合相等即可，順序無關——scope_ids 依 DB 順序、target_ids 以查詢對象為首，
            // 直接 `===` 會因順序不同而永遠不等，白跑一輪範疇總計聚合
            'target_is_scope' => $targetIsWholeSite
                ? $unrestricted
                : ($scopeIds !== null && $this->sameAgentSet($targetIds, $scopeIds)),
        ];
    }

    /**
     * 兩組代理 id 是否為同一集合（忽略順序與重複）。
     *
     * @param  array<int, int>  $a
     * @param  array<int, int>  $b
     */
    private function sameAgentSet(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * 代理範疇條件：清單短就直接用 id 陣列，過長改以 `hierarchy_path` 子查詢。
     *
     * MySQL 對數萬筆的字面 IN 清單會放棄 `created_at` 之類的時間索引；改成子查詢後
     * optimizer 走 semi-join（時間索引先收斂範圍、再以 agents 主鍵逐筆驗證）。
     *
     * `$lineRoot` 為 null（例：可視範疇不是單一條線）時無前綴可用，只能沿用 id 清單。
     *
     * 一併回傳 signature 作為 `aggregate()` 的快取鍵成分：Closure 無法序列化、id 陣列
     * 過長不宜入鍵，故以「不限制 / 某條線 / id 集合雜湊」三種穩定形式表達。
     *
     * @param  array<int, int>|null  $ids
     * @return array{0: array<int, int>|Closure|null, 1: string}
     */
    private function scopeFilter(?array $ids, ?Agent $lineRoot): array
    {
        if ($ids === null) {
            return [null, 'all'];
        }

        $path = rtrim((string) ($lineRoot?->hierarchy_path ?? ''), '/');
        $useSubquery = count($ids) > self::SCOPE_ID_LIMIT && $lineRoot !== null && $path !== '';

        if ($useSubquery) {
            return [
                $this->scopeSubquery((int) $lineRoot->id, $path . '/'),
                'line:' . $lineRoot->id,
            ];
        }

        // 排序後入雜湊：同一組代理不因查詢順序不同而產生兩份快取
        $sorted = $ids;
        sort($sorted);

        return [$ids, 'ids:' . md5(implode(',', $sorted))];
    }

    /**
     * 「自己＋該前綴底下的所有下級」子查詢。前綴補上結尾「/」當節點邊界，
     * 避免 `/1/10009` 誤中 `/1/100092`（與 `scopeAgents()` 同一規則）。
     */
    private function scopeSubquery(int $rootId, string $prefix): Closure
    {
        return function ($query) use ($rootId, $prefix) {
            $query->select('id')
                ->from('agents')
                ->where(function ($where) use ($rootId, $prefix) {
                    $where->where('id', $rootId)
                        ->orWhere('hierarchy_path', 'like', $prefix . '%');
                });
        };
    }

    /**
     * 登入者可視範疇內的代理（含自己）。
     *
     * 平台端（admin 及其子帳號）不限；一般代理以 `hierarchy_path` 前綴比對，
     * 補上「/」當節點邊界避免 `/1/10009` 誤中 `/1/100092`；查無階層時 fail-closed 只看自己。
     */
    private function scopeAgents(Agent $owner): Collection
    {
        $query = DB::table('agents')->select('id', 'parent', 'username', 'nickname', 'hierarchy_path');

        if (!$owner->isPlatformAdmin()) {
            $path = (string) $owner->hierarchy_path;

            if ($path === '') {
                $query->where('id', $owner->id);
            } else {
                $prefix = rtrim($path, '/') . '/';
                $query->where(function ($where) use ($owner, $prefix) {
                    $where->where('id', $owner->id)
                        ->orWhere('hierarchy_path', 'like', $prefix . '%');
                });
            }
        }

        return $query->get()
            ->map(function ($row) {
                $row->id = (int) $row->id;
                $row->parent = (int) $row->parent;

                return $row;
            })
            ->keyBy('id');
    }

    /** 代理線：自己＋所有下級（以 `hierarchy_path` 前綴比對） */
    public function lineIds(Collection $agents, object $target): array
    {
        $path = rtrim((string) $target->hierarchy_path, '/');
        $ids = [(int) $target->id];

        if ($path === '') {
            return $ids;
        }

        foreach ($agents as $agent) {
            if ($agent->id !== $target->id && str_starts_with((string) $agent->hierarchy_path, $path . '/')) {
                $ids[] = $agent->id;
            }
        }

        return $ids;
    }

    /**
     * 代理層級：以第一層代理為 1。
     *
     * `hierarchy_path` 形如 `/1/4249/4250`，開頭的 `1` 是平台 admin 根節點——它不會有會員，
     * 不該佔一層，故 `frank`（`/1/4249`）是 1 級、其下級（`/1/4249/4250`）是 2 級。
     * 舊資料若真有會員直掛 admin，保底回 1，不顯示 0。
     */
    public function hierarchyLevel(?string $hierarchyPath): int
    {
        $nodes = array_filter(
            explode('/', (string) $hierarchyPath),
            fn ($node) => $node !== '' && $node !== '1',
        );

        return max(1, count($nodes));
    }

    /**
     * 查詢區間：使用者所選時區的牆鐘 ⇒ UTC（DB 儲存基準）。
     *
     * 時區一律取自 `tz_offset`（與留存的日界同一個來源），不另設固定基準。
     * 二階明細直接複用，時間解析只有一份。
     *
     * @return array{0: string, 1: string} [起, 迄]（UTC）
     */
    public function range(array $input): array
    {
        $timezone = $this->timezoneOf((int) $input['tz_offset']);

        return [
            Carbon::parse($input['start_time'], $timezone)->utc()->format('Y-m-d H:i:s'),
            Carbon::parse($input['end_time'], $timezone)->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /** 偏移小時 ⇒ `+08:00` / `-05:00` 形式（新後台只提供整數時區） */
    public function timezoneOf(int $offsetHours): string
    {
        return sprintf('%s%02d:00', $offsetHours < 0 ? '-' : '+', abs($offsetHours));
    }

    /**
     * 全部指標的「直屬代理 ⇒ 指標」map，一次查完給三支端點用。
     *
     * `$agentIds` 為 null 代表不限制（平台端）。`$grouped = false` 時只回單列總計
     * （鍵固定為 0），供貢獻佔比分母使用——省掉 GROUP BY 與巨大 IN 清單，正式站才跑得動。
     *
     * **結果快取**（`$signature` 為範疇識別，見 `scopeFilter()`）：這七支聚合掃的是
     * `summary_bet_records`（千萬列級）等大表，TPower 全站整月實測單支即 6～11 秒。
     * overview / agent-list / 貢獻佔比分母跑的是**同一組聚合**，前端進頁面會同時打三支
     * 端點、翻頁與切換成本開關又會重打，故以「範疇＋區間＋時區＋是否分組」為鍵共用結果。
     *
     * @param  array<int, int>|Closure|null  $agentIds
     * @return array<string, array<int, mixed>> 指標來源 ⇒ [直屬代理 id ⇒ 該代理的指標]
     */
    private function aggregate(array|Closure|null $agentIds, array $input, string $signature, bool $grouped = true): array
    {
        $key = CacheKeys::agentDashboardAggregate(implode('|', [
            $signature,
            $input['start_time'],
            $input['end_time'],
            $input['tz_offset'],
            $grouped ? 'g' : 's',
        ]));

        return Cache::safeRemember(
            $key,
            $this->aggregateTtl($input),
            fn () => $this->runAggregate($agentIds, $input, $grouped),
        );
    }

    /**
     * 聚合快取的存活時間：已結束的區間資料不再變動，可放長；涵蓋「現在」的區間須短。
     *
     * 以使用者時區的當下為界——查昨日以前是歷史，查今日（或跨到今日）仍在累積。
     */
    public function aggregateTtl(array $input): int
    {
        $timezone = $this->timezoneOf((int) $input['tz_offset']);
        $endAt = Carbon::parse($input['end_time'], $timezone);

        return $endAt->lt(Carbon::now($timezone)->startOfDay())
            ? CacheTtl::ONE_HOUR->value
            : CacheTtl::DEFAULT->value;
    }

    /**
     * 實際執行八支聚合（快取未命中時）。
     *
     * @param  array<int, int>|Closure|null  $agentIds
     * @return array<string, array<int, mixed>>
     */
    private function runAggregate(array|Closure|null $agentIds, array $input, bool $grouped): array
    {
        [$startAt, $endAt] = $this->range($input);

        return [
            'registration' => $this->registrationByAgent($agentIds, $startAt, $endAt, $grouped),
            'deposit_order' => $this->depositOrderByAgent($agentIds, $startAt, $endAt, $grouped),
            'hourly' => $this->hourlyByAgent($agentIds, $startAt, $endAt, $grouped),
            'bet' => $this->betByAgent($agentIds, $startAt, $endAt, $grouped),
            'cost' => $this->costByAgent($agentIds, $startAt, $endAt, $grouped),
            'event_reward' => $this->eventRewardByAgent($agentIds, $startAt, $endAt, $grouped),
            'rebate' => $this->rebateByAgent($agentIds, $startAt, $endAt, $grouped),
            'recycle' => $this->recycleByAgent($agentIds, $startAt, $endAt, $grouped),
        ];
    }

    /**
     * 套用代理範疇：`null` 不加條件（平台端），否則限定直屬代理落在範疇內。
     *
     * `$agentIds` 可為 id 陣列或 `hierarchy_path` 子查詢 Closure（見 `resolveScope()`），
     * 兩者皆為 `whereIn` 原生支援的形態。
     *
     * @param  array<int, int>|Closure|null  $agentIds
     */
    public function applyScope(Builder $query, string $column, array|Closure|null $agentIds): Builder
    {
        return $agentIds === null ? $query : $query->whereIn($column, $agentIds);
    }

    /**
     * 收尾：分組時依直屬代理分組（給指標卡與代理列表逐列取），不分組時只回單列總計。
     *
     * @param  array<int, mixed>  $selects
     */
    public function finish(Builder $query, string $column, bool $grouped, array $selects): array
    {
        if ($grouped) {
            return $query
                ->groupBy($column)
                ->select([DB::raw($column . ' as parent'), ...$selects])
                ->get()
                ->keyBy('parent')
                ->all();
        }

        $row = $query->select([DB::raw('0 as parent'), ...$selects])->first();

        return $row === null ? [] : [0 => $row];
    }

    /** 註冊人數 */
    public function registrationByAgent(array|Closure|null $agentIds, string $startAt, string $endAt, bool $grouped): array
    {
        $query = $this->applyScope(
            DB::table('members')->whereBetween('created_at', [$startAt, $endAt]),
            'parent',
            $agentIds,
        );

        return $this->finish($query, 'parent', $grouped, [DB::raw('COUNT(*) as registration_count')]);
    }

    /**
     * 首充 / 複充：以每位會員**全歷史**最早的成功付款時間（`MIN(pay_time)`）判定首充。
     *
     * 不用 `members.first_deposit`——該欄位實務上幾乎沒寫入。候選會員先收斂成「期間內有成功
     * 充值的會員」，再對候選算全歷史 MIN，避免全表 GROUP BY。
     *
     * **漏斗子集口徑**（需求方定案）：註冊 ⊃ 首充 ⊃ 複充，故本查詢限定「期間內註冊」的會員，
     * 期間前註冊、期間內才首充的老會員不計入。這樣註冊轉首充率與複充率一定 ≤ 100%，
     * 二階明細的布林欄加總也會等於卡片數字。
     * 「期間內註冊」已隱含首充落在期間內（首充不可能早於註冊，且期間內有成功充值就代表最早
     * 那筆也在期間內），所以複充不必另外限制 `first_pay`。
     *
     * 同一會員同一秒有兩筆成功充值時，兩筆都會被當首充計入金額（人數仍去重）；
     * 實務極少發生，暫不處理。
     */
    private function depositOrderByAgent(array|Closure|null $agentIds, string $startAt, string $endAt, bool $grouped): array
    {
        $success = OrderStatusEnum::DEPOSIT_FINISHED->value;

        // 候選只取「期間內註冊」的會員——漏斗子集本來就只算這批人，拿全站有充值的會員當候選
        // 會白掃幾萬人（TPower 實測 35.5s ⇒ 0.5s）
        $candidates = $this->applyScope(
            DB::table('members')->select('id')->whereBetween('created_at', [$startAt, $endAt]),
            'parent',
            $agentIds,
        );

        $firstPay = DB::table('orders')
            ->select('member_id', DB::raw('MIN(pay_time) as first_pay'))
            ->where('status', $success)
            ->whereIn('member_id', $candidates)
            ->groupBy('member_id');

        $query = $this->applyScope(
            DB::table('orders as o')
                ->joinSub($firstPay, 'fp', 'fp.member_id', '=', 'o.member_id')
                ->join('members as m', 'm.id', '=', 'o.member_id')
                ->where('o.status', $success)
                ->whereBetween('o.pay_time', [$startAt, $endAt])
                ->whereBetween('m.created_at', [$startAt, $endAt]),
            'm.parent',
            $agentIds,
        );

        return $this->finish($query, 'm.parent', $grouped, [
            DB::raw('COUNT(DISTINCT CASE WHEN o.pay_time = fp.first_pay THEN o.member_id END) as first_deposit_count'),
            DB::raw('SUM(CASE WHEN o.pay_time = fp.first_pay THEN o.point ELSE 0 END) as first_deposit_amount'),
            DB::raw('COUNT(DISTINCT CASE WHEN o.pay_time > fp.first_pay THEN o.member_id END) as repeat_deposit_count'),
            DB::raw('SUM(CASE WHEN o.pay_time > fp.first_pay THEN o.point ELSE 0 END) as repeat_deposit_amount'),
        ]);
    }

    /**
     * 總充值 / 總提現與六類來源明細：走 `member_hourly_statistics`（成功狀態已固化在寫入端）。
     *
     * 來源明細欄由 `MemberHourlyStatisticsRecomputeService` 寫入（target/tickets#4333）：
     * 三方充值／自助上分（orders 依金流商是否 AutoDeposit）、後台開分（transaction_logs type 4、order_id 0）、
     * 三方提現（withdrawal_orders status 24）／自助洗分（status 4）、後台洗分（type 5、order_id 0）。
     *
     * 該表為整點桶，區間起點對齊整點才不會漏掉起始的部分小時。
     */
    private function hourlyByAgent(array|Closure|null $agentIds, string $startAt, string $endAt, bool $grouped): array
    {
        $startHour = Carbon::parse($startAt)->startOfHour()->toDateTimeString();

        $query = $this->applyScope(
            DB::table('member_hourly_statistics as mhs')
                ->join('members as m', 'm.id', '=', 'mhs.member_id')
                ->whereBetween('mhs.summary_hour', [$startHour, $endAt]),
            'm.parent',
            $agentIds,
        );

        return $this->finish($query, 'm.parent', $grouped, [
            DB::raw('COUNT(DISTINCT CASE WHEN mhs.deposit_count > 0 THEN mhs.member_id END) as deposit_member_count'),
            DB::raw('SUM(mhs.deposit_amount) as deposit_amount'),
            DB::raw('COUNT(DISTINCT CASE WHEN mhs.withdrawal_count > 0 THEN mhs.member_id END) as withdrawal_member_count'),
            DB::raw('SUM(mhs.withdrawal_amount) as withdrawal_amount'),
            DB::raw('SUM(mhs.third_party_deposit_amount) as third_party_deposit_amount'),
            DB::raw('SUM(mhs.auto_deposit_amount) as auto_deposit_amount'),
            DB::raw('SUM(mhs.admin_deposit_amount) as admin_deposit_amount'),
            DB::raw('SUM(mhs.third_party_withdrawal_amount) as third_party_withdrawal_amount'),
            DB::raw('SUM(mhs.auto_withdrawal_amount) as auto_withdrawal_amount'),
            DB::raw('SUM(mhs.admin_withdrawal_amount) as admin_withdrawal_amount'),
        ]);
    }

    /**
     * 投注 / 派獎 / GGR / 遊戲商盤費。
     *
     * 排除 `custom.report.excluded_vendor_ids` 內的遊戲商（對齊 SR11 平台對帳表預設不勾選的那批），
     * 投注、輸贏、盤費、投注人數一併不計。
     *
     * `win_lose` 為會員淨輸贏 ⇒ `派獎 = bet + win_lose`、`GGR = −win_lose`（口徑定案）。
     * `cost` 為盤費，由 `SummaryBetRecordService::calculateGameFee()` 以
     * `−(win_lose × 盤費率)` 寫入：會員輸（公司賺）時為正、會員贏時為負，原樣加總即為期間盤費。
     */
    private function betByAgent(array|Closure|null $agentIds, string $startAt, string $endAt, bool $grouped): array
    {
        $query = $this->applyScope(
            DB::table('summary_bet_records as sbr')
                ->join('members as m', 'm.id', '=', 'sbr.member_id')
                ->whereBetween('sbr.summary_time', [$startAt, $endAt])
                // 對齊 SR11 平台對帳表：env 排除清單內的遊戲商不計（見 SummaryBetRecord::reportExcludedVendorIds）
                ->whereNotIn('sbr.vendor_id', SummaryBetRecord::reportExcludedVendorIds()),
            'm.parent',
            $agentIds,
        );

        return $this->finish($query, 'm.parent', $grouped, [
            DB::raw('SUM(sbr.bet) as bet_amount'),
            DB::raw('SUM(sbr.win_lose) as win_lose'),
            DB::raw('SUM(sbr.cost) as vendor_fee'),
            // 有注單即計入（對齊舊「數據分析」）：`bet` 是**有效投注**，0 是正常值——
            // 有效投注為 0 的注單仍是投注行為，不該把人排除
            DB::raw('COUNT(DISTINCT sbr.member_id) as real_bet_member_count'),
        ]);
    }

    /**
     * 事件類成本（活動 V2 / 禮碼 / VIP / 支付手續費 / 簡訊）：`summary_event_records` 依 `event_id` 分桶。
     *
     * @return array<int, array<string, string>> agentId ⇒ [成本項 ⇒ 金額]
     */
    public function costByAgent(array|Closure|null $agentIds, string $startAt, string $endAt, bool $grouped): array
    {
        $eventIds = array_merge(...array_values(self::COST_EVENT_IDS));

        $query = $this->applyScope(
            DB::table('summary_event_records as ser')
                ->join('members as m', 'm.id', '=', 'ser.member_id')
                ->whereIn('ser.event_id', $eventIds)
                ->whereBetween('ser.summary_time', [$startAt, $endAt]),
            'm.parent',
            $agentIds,
        );

        $rows = $grouped
            ? $query->groupBy('m.parent', 'ser.event_id')
                ->select(DB::raw('m.parent as parent'), 'ser.event_id', DB::raw('SUM(ser.point) as point'))
                ->get()
            : $query->groupBy('ser.event_id')
                ->select(DB::raw('0 as parent'), 'ser.event_id', DB::raw('SUM(ser.point) as point'))
                ->get();

        $result = [];

        foreach ($rows as $row) {
            $key = $this->costKeyOf((int) $row->event_id);

            if ($key === null) {
                continue;
            }

            $agentId = (int) $row->parent;
            $result[$agentId][$key] = Common::numAdd(
                $result[$agentId][$key] ?? '0',
                $this->money($row->point),
                4,
            );
        }

        return $result;
    }

    /**
     * 活動獎勵（活動成本的主體）：`transaction_logs` ＋ 多錢包 `wallet_transaction_logs`。
     *
     * 只計成功狀態（`status = 1000`）；TPower 實測這些類型的交易全為成功，故與 newsr11 數字一致。
     */
    public function eventRewardByAgent(array|Closure|null $agentIds, string $startAt, string $endAt, bool $grouped): array
    {
        $success = TransactionStatusEnum::STATUS_SUCCESS->value;

        $sources = [
            ['transaction_logs', self::EVENT_REWARD_TX_TYPES],
            ['wallet_transaction_logs', self::EVENT_REWARD_WALLET_TX_TYPES],
        ];

        $result = [];

        foreach ($sources as [$table, $types]) {
            $query = $this->applyScope(
                DB::table($table . ' as t')
                    ->join('members as m', 'm.id', '=', 't.member_id')
                    ->where('t.status', $success)
                    ->whereIn('t.type', $types)
                    ->whereBetween('t.created_at', [$startAt, $endAt]),
                'm.parent',
                $agentIds,
            );

            foreach ($this->finish($query, 'm.parent', $grouped, [DB::raw('SUM(t.point) as event_reward')]) as $agentId => $row) {
                $result[$agentId] = (object) [
                    'event_reward' => Common::numAdd(
                        (string) ($result[$agentId]->event_reward ?? '0'),
                        $this->money($row->event_reward),
                        4,
                    ),
                ];
            }
        }

        return $result;
    }

    /**
     * 返水成本：對齊 newsr11 的「3 layers of agents mode」（三層代分潤）與「全民代理」（無限代獎勵）兩列。
     *
     * 四張原始表各自加總後相加：代理層表（`*_agents`）的分潤屬於該代理本人，以 `agent_id` 歸戶進
     * 「直屬代理 ⇒ 指標」map；會員層表以會員的直屬代理歸戶。篩選條件（`reward_type = 1`、金額 > 0、
     * 無限代 `TRUNCATE(bonus_direct, 2)`）逐字沿用 newsr11，數字才對得上。
     *
     * @return array<int, string> agentId ⇒ 金額
     */
    public function rebateByAgent(array|Closure|null $agentIds, string $startAt, string $endAt, bool $grouped): array
    {
        $sources = [
            [
                DB::table('three_hierarchy_bonus_report_agents as t')->where('t.rebate_amount', '>', 0),
                't.agent_id',
                'SUM(t.rebate_amount)',
            ],
            [
                DB::table('three_hierarchy_bonus_report as t')
                    ->join('members as m', 'm.id', '=', 't.member_id')
                    ->where('t.reward_type', 1)
                    ->whereRaw('(t.rebate + t.down_rebate + t.down2_rebate) > 0'),
                'm.parent',
                'SUM(t.rebate + t.down_rebate + t.down2_rebate)',
            ],
            [
                DB::table('unlimit_bonusreport_agents as t')->whereRaw('TRUNCATE(t.bonus_direct, 2) > 0'),
                't.agent_id',
                'SUM(TRUNCATE(t.bonus_direct, 2))',
            ],
            [
                DB::table('unlimit_bonusreport as t')
                    ->join('members as m', 'm.id', '=', 't.member_id')
                    ->whereRaw('TRUNCATE(t.bonus_direct, 2) > 0'),
                'm.parent',
                'SUM(TRUNCATE(t.bonus_direct, 2))',
            ],
        ];

        return $this->sumSources($sources, 't.created_at', $agentIds, $startAt, $endAt, $grouped);
    }

    /**
     * 回收金額：活動 V2 錢包剩餘回收（`event_v2_recycles`），對齊 newsr11 eventCost 列的「回收金額」。
     *
     * 回傳正數，前端計入 NGR 時為加回（與 newsr11 總咬分 = 彩金與活動 ＋ 回收金額 同向）。
     * 各回收狀態一律計入，與 newsr11 相同。
     *
     * @return array<int, string> agentId ⇒ 金額
     */
    private function recycleByAgent(array|Closure|null $agentIds, string $startAt, string $endAt, bool $grouped): array
    {
        $sources = [[
            DB::table('event_v2_recycles as r')
                ->join('event_v2_user_joins as j', 'j.id', '=', 'r.event_v2_user_join_id')
                ->join('members as m', 'm.id', '=', 'j.member_id'),
            'm.parent',
            'SUM(r.recycle_amount)',
        ]];

        return $this->sumSources($sources, 'r.created_at', $agentIds, $startAt, $endAt, $grouped);
    }

    /**
     * 多來源加總成「直屬代理 ⇒ 金額」：每個來源套範疇、套時間、依歸戶欄 GROUP BY 後相加。
     *
     * @param  array<int, array{0: Builder, 1: string, 2: string}>  $sources  [query, 歸戶欄, SUM 表達式]
     * @return array<int, string>
     */
    private function sumSources(array $sources, string $timeColumn, array|Closure|null $agentIds, string $startAt, string $endAt, bool $grouped): array
    {
        $result = [];

        foreach ($sources as [$query, $column, $sum]) {
            $query = $this->applyScope($query->whereBetween($timeColumn, [$startAt, $endAt]), $column, $agentIds);

            foreach ($this->finish($query, $column, $grouped, [DB::raw($sum . ' as amount')]) as $agentId => $row) {
                $result[$agentId] = Common::numAdd($result[$agentId] ?? '0', $this->money($row->amount), 4);
            }
        }

        return $result;
    }

    /** `event_id` ⇒ 成本項 key */
    private function costKeyOf(int $eventId): ?string
    {
        foreach (self::COST_EVENT_IDS as $key => $ids) {
            if (in_array($eventId, $ids, true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * 留存一列：D1 / D7 / D30 各自的回訪人數。
     *
     * 日界一律帶使用者時區偏移（`tz_offset`）——前端只平移區間端點，「一天」由後端決定。
     */
    private function retentionRow(
        Builder $query,
        string $behaviorColumn,
        array|Closure|null $agentIds,
        string $startAt,
        string $endAt,
        int $tz,
    ): array {
        $diff = "DATEDIFF(DATE(DATE_ADD({$behaviorColumn}, INTERVAL ? HOUR)), DATE(DATE_ADD(m.created_at, INTERVAL ? HOUR)))";

        $selects = [];
        $bindings = [];

        foreach (self::RETENTION_DAYS as $day) {
            $selects[] = "COUNT(DISTINCT CASE WHEN {$diff} = ? THEN m.id END) as d{$day}";
            $bindings[] = $tz;
            $bindings[] = $tz;
            $bindings[] = $day;
        }

        $row = $this->applyScope($query, 'm.parent', $agentIds)
            ->whereBetween('m.created_at', [$startAt, $endAt])
            ->selectRaw(implode(', ', $selects), $bindings)
            ->first();

        return [
            'd1' => (int) ($row->d1 ?? 0),
            'd7' => (int) ($row->d7 ?? 0),
            'd30' => (int) ($row->d30 ?? 0),
        ];
    }

    /**
     * 期間內有數據的代理 id（含其所有上級）。
     *
     * 先找出「自身直屬會員有數據」的代理，再沿 `hierarchy_path` 把它們的上級一併標記——
     * 否則代理明細在 `self` 模式下會藏掉「自身 0、下級有資料」的代理，那條展開路徑就斷了。
     *
     * @param  array<string, array<int, mixed>>  $maps
     * @return array<int, true>
     */
    private function agentIdsWithData(array $maps, Collection $agents): array
    {
        $result = [];

        foreach ($maps as $rows) {
            foreach (array_keys($rows) as $agentId) {
                $agentId = (int) $agentId;

                if (isset($result[$agentId]) || !$this->hasAnyValue($this->foldMetrics($maps, [$agentId]))) {
                    continue;
                }

                $result[$agentId] = true;

                // 標記所有上級（hierarchy_path 形如 /1/4249/4250）
                foreach (explode('/', trim((string) ($agents->get($agentId)->hierarchy_path ?? ''), '/')) as $node) {
                    if ($node !== '') {
                        $result[(int) $node] = true;
                    }
                }
            }
        }

        return $result;
    }

    /** 一組指標是否有任何非零值（人數、金額、成本皆算） */
    private function hasAnyValue(array $metrics): bool
    {
        $numbers = [
            $metrics['registration']['count'],
            $metrics['first_deposit']['count'],
            $metrics['repeat_deposit']['count'],
            $metrics['real_bet_member_count'],
            $metrics['deposit']['member_count'],
            $metrics['withdrawal']['member_count'],
        ];

        foreach ($numbers as $count) {
            if ($count !== 0) {
                return true;
            }
        }

        $amounts = [
            $metrics['first_deposit']['amount'],
            $metrics['repeat_deposit']['amount'],
            $metrics['deposit']['amount'],
            $metrics['withdrawal']['amount'],
            $metrics['bet']['amount'],
            $metrics['bet']['payout'],
            ...array_values($metrics['cost']),
        ];

        foreach ($amounts as $amount) {
            if (Common::numCompare((string) $amount, '0', 4) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 把「直屬代理 ⇒ 指標」map 收斂成一組指標（傳入的 agent id 清單即彙總範圍）。
     *
     * 每位會員只屬一個直屬代理，故同一份 map 內按 id 相加天然去重，不會父子重複計算。
     * `$agentIds` 為 null 時取 map 內的全部鍵（單列總計或整條線的加總）。
     *
     * @param  array<int, int>|null  $agentIds
     */
    private function foldMetrics(array $maps, ?array $agentIds): array
    {
        if ($agentIds === null) {
            $agentIds = collect($maps)
                ->flatMap(fn ($rows) => array_keys($rows))
                ->unique()
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $sum = [
            'registration_count' => 0,
            'first_deposit_count' => 0,
            'repeat_deposit_count' => 0,
            'deposit_member_count' => 0,
            'withdrawal_member_count' => 0,
            'real_bet_member_count' => 0,
        ];
        $money = [
            'first_deposit_amount' => '0',
            'repeat_deposit_amount' => '0',
            'deposit_amount' => '0',
            'withdrawal_amount' => '0',
            'third_party_deposit_amount' => '0',
            'auto_deposit_amount' => '0',
            'admin_deposit_amount' => '0',
            'third_party_withdrawal_amount' => '0',
            'auto_withdrawal_amount' => '0',
            'admin_withdrawal_amount' => '0',
            'bet_amount' => '0',
            'win_lose' => '0',
        ];
        $cost = array_fill_keys([...array_keys(self::COST_EVENT_IDS), 'rebate', 'vendor_fee', 'recycle'], '0');

        foreach ($agentIds as $agentId) {
            foreach (['registration', 'deposit_order', 'hourly', 'bet'] as $source) {
                $row = $maps[$source][$agentId] ?? null;

                if ($row === null) {
                    continue;
                }

                foreach ($sum as $key => $value) {
                    if (isset($row->{$key})) {
                        $sum[$key] = $value + (int) $row->{$key};
                    }
                }

                foreach ($money as $key => $value) {
                    if (isset($row->{$key})) {
                        $money[$key] = Common::numAdd($value, $this->money($row->{$key}), 4);
                    }
                }
            }

            $row = $maps['bet'][$agentId] ?? null;
            if ($row !== null && isset($row->vendor_fee)) {
                $cost['vendor_fee'] = Common::numAdd($cost['vendor_fee'], $this->money($row->vendor_fee), 4);
            }

            foreach ($maps['cost'][$agentId] ?? [] as $key => $amount) {
                $cost[$key] = Common::numAdd($cost[$key], $amount, 4);
            }

            // 活動成本 = 活動獎勵（交易表）＋ 活動 V2 成本（event_id 7，已在上面併入）
            $reward = $maps['event_reward'][$agentId] ?? null;
            if ($reward !== null) {
                $cost['event'] = Common::numAdd($cost['event'], $this->money($reward->event_reward), 4);
            }

            foreach (['rebate', 'recycle'] as $key) {
                $cost[$key] = Common::numAdd($cost[$key], $maps[$key][$agentId] ?? '0', 4);
            }
        }

        // 派獎 = 投注 + 會員淨輸贏；GGR = −會員淨輸贏
        $payout = Common::numAdd($money['bet_amount'], $money['win_lose'], 4);
        $ggr = Common::numMul($money['win_lose'], '-1', 4);

        return [
            'registration' => ['count' => $sum['registration_count']],
            'first_deposit' => [
                'count' => $sum['first_deposit_count'],
                'amount' => $money['first_deposit_amount'],
            ],
            'repeat_deposit' => [
                'count' => $sum['repeat_deposit_count'],
                'amount' => $money['repeat_deposit_amount'],
            ],
            'real_bet_member_count' => $sum['real_bet_member_count'],
            'deposit' => [
                'member_count' => $sum['deposit_member_count'],
                'amount' => $money['deposit_amount'],
            ],
            'withdrawal' => [
                'member_count' => $sum['withdrawal_member_count'],
                'amount' => $money['withdrawal_amount'],
            ],
            // 充提六類來源明細（member_hourly_statistics 的 *_amount 欄，target/tickets#4333 口徑）
            'deposit_sources' => [
                'third_party' => $money['third_party_deposit_amount'],
                'auto' => $money['auto_deposit_amount'],
                'admin' => $money['admin_deposit_amount'],
            ],
            'withdrawal_sources' => [
                'third_party' => $money['third_party_withdrawal_amount'],
                'auto' => $money['auto_withdrawal_amount'],
                'admin' => $money['admin_withdrawal_amount'],
            ],
            'bet' => [
                'amount' => $money['bet_amount'],
                'payout' => $payout,
                'ggr' => $ggr,
            ],
            'cost' => [
                'event' => $cost['event'],
                'rebate' => $cost['rebate'],
                'rolling_chip' => $cost['rolling_chip'],
                'vip' => $cost['vip'],
                'payment_fee' => $cost['payment_fee'],
                'withdrawal_fee' => $cost['withdrawal_fee'],
                'vendor_fee' => $cost['vendor_fee'],
                'sms' => $cost['sms'],
                'recycle' => $cost['recycle'],
            ],
        ];
    }

    /** DB decimal / 外部數值入境正規化（避免 float 精度誤差流入運算） */
    private function money(mixed $value): string
    {
        return Common::numAdd('0', (string) ($value ?? '0'), 4);
    }
}
