<?php

declare(strict_types=1);

namespace App\Console\Commands\Currency;

use App\Enums\AgentMigrateLogTypeEnum;
use App\Enums\MemberStatusEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberAgent;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 量測會員幣別解析鏈每一跳斷了多少，並為孤兒 parent 的會員蒐集歸屬線索。
 *
 * 解析鏈：members.parent → agents.organization_id → organizations.currency_id → currencies.id。
 * 本命令回答兩個問題：
 *   1. 這個站有多少會員解析不出幣別、斷在哪一跳？（六站基準值，決定治理只涵蓋 TPower 或六站全查）
 *   2. 斷在第一跳的會員，parent 能不能從既有資料推回來？（歸屬提案的量化依據）
 *
 * 判定值（依鏈路順序，前者成立即停）：
 *   parent_zero                 members.parent <= 0，上層未指派的 sentinel
 *   parent_missing              parent > 0 但 agents 無此列（孤兒 parent）
 *   organization_missing        代理存在，但 agents.organization_id 指到不存在的組織（含 0；六站 09-07 實查皆 0 列，未再細分）
 *   organization_currency_zero  組織存在，但 organizations.currency_id <= 0（幣別未設定）
 *   currency_not_in_master      組織有幣別 id，但 currencies 主檔無此列
 *   resolved                    四跳皆通
 *
 * 前五種在執行期都會走 Member::currency_id 存取子的 `?? 0` 分支、寫出 sentinel 0；
 * 唯 currency_not_in_master 例外——存取子不查主檔，它會被當成「解析成功」寫進一個不存在的 id，
 * 比 0 更難被稽核抓到。
 *
 * 歸屬線索（只對第一跳斷掉的兩群會員計算）：
 *   descendant agents   缺失 parent 仍出現在其他代理的 agents.hierarchy_path 裡 ⇒ 該代理曾存在，
 *                       組織可由子代理推回（子代理組織一致時才給值）。做法是把 agents 的 hierarchy_path
 *                       一次撈回、在 PHP 端建「祖先 id → 子代理數／組織集合」索引，成本與 --top 無關。
 *                       hierarchy_path 為 NULL 的代理進不了索引，另以 agents_without_hierarchy_path 報出，
 *                       這條線索因此是低估。
 *   member_agent        member_agent.parent_id 指向仍存在的代理 ⇒ 可直接推回
 *   agent_migrate_logs  type=會員 的搬移紀錄、目標代理仍存在 ⇒ 可由搬移紀錄推回
 *   transaction_logs.agent_id 是「開洗分的操作代理」不是歸屬，刻意不採。
 *   ⚠️ 六站 09-07 實查 agent_migrate_logs 皆為空表。線索為 0 有兩種意思——「查過了推不回」與
 *      「這條路從沒被寫過資料」——所以報表另附 hint_sources（兩張線索表的列數），0 線索先看來源有沒有料。
 *
 * ⚠️ 唯讀、只量測不改資料；資料處置另拍板。輸出是某時間點的快照，資料會漂移。
 * ⚠️ 成本（實際查詢數，不是「一次」）：members LEFT JOIN agents 全表掃 6 次（鏈路狀態 1、缺失 parent 分組與
 *    相異數 2、孤兒群三條線索 3）＋ parent <= 0 的索引範圍 3 次 ＋ agents 全表 1 次（建索引）＋ 兩張線索表
 *    各 1 次 COUNT。TPower（約 100 萬會員、4.5 萬代理）圓桌實測前一版 63 秒，本版少兩次 members 掃描、
 *    不再逐 parent 做 LIKE，量級相同——請在從庫跑。
 *
 * 使用範例：
 *   php artisan currency:audit-orphan-parent
 *   php artisan currency:audit-orphan-parent --json --top=50
 *   docker exec -e DB_CONNECTION=TPower php-fpm8 bash -c "cd /var/www/APIs && php artisan currency:audit-orphan-parent"
 *
 * @knowledge rules/currency-and-wallet-currency
 */
class AuditOrphanParentCommand extends Command
{
    protected $signature = 'currency:audit-orphan-parent
                            {--json : 以 JSON 輸出，供程式解析}
                            {--top=20 : 列出會員數最多的前 N 個缺失 parent（各附子代理線索）}';

    protected $description = '量測會員幣別解析鏈每一跳斷了多少，並為孤兒 parent 的會員蒐集歸屬線索（唯讀）';

    /**
     * 鏈路狀態，依判定順序排列。
     *
     * 輸出固定照這個順序、沒命中的狀態補 0，讓六站報表逐列可比。
     */
    private const array STATES = [
        'parent_zero',
        'parent_missing',
        'organization_missing',
        'organization_currency_zero',
        'currency_not_in_master',
        'resolved',
    ];

    /**
     * 判定 SQL。與 Member::currency_id 存取子走同一條鏈，只多對最後一跳的主檔。
     */
    private const string STATE_SQL = <<<'SQL'
        CASE
            WHEN members.parent <= 0 THEN 'parent_zero'
            WHEN agents.id IS NULL THEN 'parent_missing'
            WHEN organizations.id IS NULL THEN 'organization_missing'
            WHEN organizations.currency_id <= 0 THEN 'organization_currency_zero'
            WHEN currencies.id IS NULL THEN 'currency_not_in_master'
            ELSE 'resolved'
        END
        SQL;

    /** 一次撈 agents 建祖先索引時的分批大小。 */
    private const int AGENT_CHUNK = 5000;

    public function handle(): int
    {
        $top = (int) $this->option('top');

        if ($top < 1) {
            $this->error('--top 必須是正整數');

            return self::INVALID;
        }

        $states = $this->chainStates();
        $membersTotal = array_sum(array_column($states, 'total'));
        $unresolved = $membersTotal - $states['resolved']['total'];

        $report = [
            'connection' => DB::connection()->getName(),
            'members_total' => $membersTotal,
            'unresolved_total' => $unresolved,
            'states' => array_values($states),
            'missing_parents' => $this->missingParents($top, $states['parent_missing']['total']),
            'hint_sources' => $this->hintSources(),
            'hints' => [
                'parent_missing' => $this->hopOneHints($this->orphanQuery(), $states['parent_missing']['total']),
                'parent_zero' => $this->hopOneHints($this->parentZeroQuery(), $states['parent_zero']['total']),
            ],
        ];

        if ($this->option('json')) {
            $this->renderJson($report);
        } else {
            $this->renderTable($report);
        }

        return $unresolved === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 每個鏈路狀態的會員數、啟用中會員數、相異 parent 數與最後登入。
     *
     * @return array<string, array{state: string, total: int, active: int, distinct_parents: int, share_pct: float, last_login: string|null}>
     */
    private function chainStates(): array
    {
        $rows = $this->chainQuery()
            ->selectRaw(self::STATE_SQL . ' AS chain_state')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(members.status = ?) AS active', [MemberStatusEnum::VERIFIED->value])
            ->selectRaw('COUNT(DISTINCT members.parent) AS distinct_parents')
            ->selectRaw('MAX(members.last_login) AS last_login')
            ->groupBy('chain_state')
            ->get()
            ->keyBy('chain_state');

        $membersTotal = (int) $rows->sum('total');
        $states = [];

        foreach (self::STATES as $state) {
            $row = $rows->get($state);
            $total = (int) ($row?->total ?? 0);

            $states[$state] = [
                'state' => $state,
                'total' => $total,
                'active' => (int) ($row?->active ?? 0),
                'distinct_parents' => (int) ($row?->distinct_parents ?? 0),
                'share_pct' => $membersTotal === 0 ? 0.0 : round($total / $membersTotal * 100, 2),
                // 這群人最後一次登入是何時：判斷是死帳還是活人，資料處置提案的主要依據
                'last_login' => $row?->last_login === null ? null : (string) $row->last_login,
            ];
        }

        return $states;
    }

    /**
     * 會員數最多的前 N 個缺失 parent，各附子代理線索，並報出前 N 名涵蓋了多少孤兒會員。
     *
     * 缺失 parent 在 TPower 有近 2 萬個，前 20 名只涵蓋約三成孤兒會員；沒有覆蓋率，讀者會把
     * 「前 N 名都推不回」外推成「全部都推不回」。
     *
     * @return array{distinct_total: int, covered_members: int, covered_pct: float, agents_without_hierarchy_path: int, top: array<int, array<string, mixed>>}
     */
    private function missingParents(int $top, int $orphanTotal): array
    {
        $rows = $this->orphanQuery()
            ->selectRaw('members.parent AS parent')
            ->selectRaw('COUNT(*) AS member_count')
            ->selectRaw('SUM(members.status = ?) AS active', [MemberStatusEnum::VERIFIED->value])
            ->selectRaw('MIN(members.created_at) AS first_created')
            ->selectRaw('MAX(members.created_at) AS last_created')
            ->selectRaw('MAX(members.last_login) AS last_login')
            ->groupBy('members.parent')
            ->orderByDesc('member_count')
            ->orderBy('members.parent')
            ->limit($top)
            ->get();

        $index = $this->descendantIndex();
        $topRows = [];
        $covered = 0;

        foreach ($rows as $row) {
            $parent = (int) $row->parent;
            $members = (int) $row->member_count;
            $covered += $members;
            $descendants = $this->descendantAgents($parent, $index['ancestors']);

            $topRows[] = [
                'parent' => $parent,
                'members' => $members,
                'active' => (int) $row->active,
                'first_created' => (string) $row->first_created,
                'last_created' => (string) $row->last_created,
                'last_login' => $row->last_login === null ? null : (string) $row->last_login,
                'descendant_agents' => $descendants['agents'],
                'descendant_organizations' => $descendants['organizations'],
                'inferred_organization_id' => $descendants['organization_id'],
            ];
        }

        return [
            'distinct_total' => $this->orphanQuery()->distinct()->count('members.parent'),
            'covered_members' => $covered,
            'covered_pct' => $orphanTotal === 0 ? 0.0 : round($covered / $orphanTotal * 100, 2),
            'agents_without_hierarchy_path' => $index['without_path'],
            'top' => $topRows,
        ];
    }

    /**
     * 把 agents.hierarchy_path 一次撈回，建「祖先代理 id → 子代理數／子代理的組織集合」索引。
     *
     * hierarchy_path 形如 /1/4249/105827（每段一層代理 id、含自己）。parent 若曾存在且有下線代理，
     * 被刪後子代理的路徑仍帶著它——這是「代理被刪」與「id 從來不存在」的區分依據，也是組織能否
     * 由子代理推回的依據。用索引取代逐 parent 的 LIKE 掃描：TPower 4.5 萬代理一次掃完，
     * 不會因 --top 放大成上萬次全表掃描。
     *
     * @return array{ancestors: array<int, array{agents: int, organizations: array<int, true>}>, without_path: int}
     */
    private function descendantIndex(): array
    {
        $ancestors = [];

        Agent::query()
            ->toBase()
            ->select(['id', 'hierarchy_path', 'organization_id'])
            ->whereNotNull('hierarchy_path')
            ->orderBy('id')
            ->chunk(self::AGENT_CHUNK, static function (Collection $agents) use (&$ancestors): void {
                foreach ($agents as $agent) {
                    $self = (int) $agent->id;
                    $organizationId = (int) $agent->organization_id;

                    foreach (explode('/', (string) $agent->hierarchy_path) as $segment) {
                        if ($segment === '' || !ctype_digit($segment)) {
                            continue;
                        }

                        $ancestor = (int) $segment;

                        if ($ancestor === $self) {
                            continue;
                        }

                        $ancestors[$ancestor] ??= ['agents' => 0, 'organizations' => []];
                        $ancestors[$ancestor]['agents']++;
                        $ancestors[$ancestor]['organizations'][$organizationId] = true;
                    }
                }
            });

        return [
            'ancestors' => $ancestors,
            'without_path' => Agent::query()->toBase()->whereNull('hierarchy_path')->count(),
        ];
    }

    /**
     * 從祖先索引取某個缺失 parent 的子代理線索；子代理全在同一組織才給組織 id，分屬多個組織推不出唯一答案。
     *
     * @param  array<int, array{agents: int, organizations: array<int, true>}>  $ancestors
     * @return array{agents: int, organizations: int, organization_id: int|null}
     */
    private function descendantAgents(int $parent, array $ancestors): array
    {
        $entry = $ancestors[$parent] ?? null;

        if ($entry === null) {
            return ['agents' => 0, 'organizations' => 0, 'organization_id' => null];
        }

        $organizationIds = array_keys($entry['organizations']);

        return [
            'agents' => $entry['agents'],
            'organizations' => count($organizationIds),
            'organization_id' => count($organizationIds) === 1 ? (int) $organizationIds[0] : null,
        ];
    }

    /**
     * 兩張線索表各有多少料。線索為 0 時先看這裡：來源本身是空的，「無線索」就不能當成「推不回」的證據。
     *
     * @return array{member_agent_rows: int, migrate_log_member_rows: int}
     */
    private function hintSources(): array
    {
        return [
            'member_agent_rows' => MemberAgent::query()->toBase()->count(),
            // agent_migrate_logs 沒有 Model（APIs 只讀不寫），聚合查詢直接走 query builder
            'migrate_log_member_rows' => DB::table('agent_migrate_logs')
                ->where('type', AgentMigrateLogTypeEnum::MEMBER->value)
                ->count(),
        ];
    }

    /**
     * 第一跳斷掉的一群會員，有多少能從其他表推回歸屬。
     *
     * total 由鏈路狀態統計傳入，不另查一次——省一趟 members，也避免兩次查詢相隔幾十秒後出現
     * hints.total 與 states.total 不一致的自相矛盾輸出。via_member_agent 與 via_migrate_log 互有重疊，
     * 故另給 any_hint／no_hint 兩個互斥合計；no_hint 只計這兩條會員層級的線索，子代理線索是
     * parent 層級的、記在 missing_parents，不在此相減。
     *
     * @return array{total: int, via_member_agent: int, via_migrate_log: int, any_hint: int, no_hint: int}
     */
    private function hopOneHints(Builder $cohort, int $total): array
    {
        $viaMemberAgent = (clone $cohort)
            ->whereExists($this->memberAgentHint())
            ->count();

        $viaMigrateLog = (clone $cohort)
            ->whereExists($this->migrateLogHint())
            ->count();

        $anyHint = (clone $cohort)
            ->where(function (Builder $query): void {
                $query->whereExists($this->memberAgentHint())
                    ->orWhereExists($this->migrateLogHint());
            })
            ->count();

        return [
            'total' => $total,
            'via_member_agent' => $viaMemberAgent,
            'via_migrate_log' => $viaMigrateLog,
            'any_hint' => $anyHint,
            'no_hint' => $total - $anyHint,
        ];
    }

    /**
     * 會員的 member_agent 紀錄，且其 parent_id 指向仍存在的代理。
     */
    private function memberAgentHint(): Builder
    {
        return MemberAgent::query()
            ->toBase()
            ->selectRaw('1')
            ->join('agents AS hinted', 'hinted.id', '=', 'member_agent.parent_id')
            ->whereColumn('member_agent.member_id', 'members.id');
    }

    /**
     * 會員的搬移紀錄，且搬入的目標代理仍存在。
     *
     * agent_migrate_logs 沒有 Model（APIs 只讀不寫），存在性查詢直接走 query builder。
     */
    private function migrateLogHint(): Builder
    {
        return DB::table('agent_migrate_logs')
            ->selectRaw('1')
            ->join('agents AS migrated', 'migrated.id', '=', 'agent_migrate_logs.agent_id')
            ->where('agent_migrate_logs.type', AgentMigrateLogTypeEnum::MEMBER->value)
            ->whereColumn('agent_migrate_logs.member_id', 'members.id');
    }

    /**
     * 全體會員沿解析鏈往下 LEFT JOIN；哪一跳對不到，該表的欄位就是 NULL。
     */
    private function chainQuery(): Builder
    {
        return Member::query()
            ->toBase()
            ->leftJoin('agents', 'agents.id', '=', 'members.parent')
            ->leftJoin('organizations', 'organizations.id', '=', 'agents.organization_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'organizations.currency_id');
    }

    /**
     * 第一跳斷在「parent 指向不存在代理」的會員。
     */
    private function orphanQuery(): Builder
    {
        return Member::query()
            ->toBase()
            ->leftJoin('agents', 'agents.id', '=', 'members.parent')
            ->where('members.parent', '>', 0)
            ->whereNull('agents.id');
    }

    /**
     * 第一跳斷在「parent 未指派（<= 0）」的會員。
     */
    private function parentZeroQuery(): Builder
    {
        return Member::query()
            ->toBase()
            ->where('members.parent', '<=', 0);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderJson(array $report): void
    {
        // JSON_PRESERVE_ZERO_FRACTION：share_pct／covered_pct 是 float，整數值不能退化成 int（0 vs 0.0），
        // 六站報表若用強型別解析會踩到
        $this->line(json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderTable(array $report): void
    {
        /** @var array<int, array<string, mixed>> $states */
        $states = $report['states'];
        /** @var array{distinct_total: int, covered_members: int, covered_pct: float, agents_without_hierarchy_path: int, top: array<int, array<string, mixed>>} $missing */
        $missing = $report['missing_parents'];
        /** @var array{member_agent_rows: int, migrate_log_member_rows: int} $sources */
        $sources = $report['hint_sources'];
        /** @var array<string, array<string, int>> $hints */
        $hints = $report['hints'];
        $membersTotal = (int) $report['members_total'];
        $unresolved = (int) $report['unresolved_total'];

        $this->line('連線：' . (string) $report['connection']);
        $this->line(sprintf('會員總數 %d，其中 %d 名解析不出主檔幣別', $membersTotal, $unresolved));

        $this->table(
            ['鏈路狀態', '會員數', '佔比 %', '啟用中', '相異 parent 數', '最後登入'],
            array_map(static fn (array $state): array => [
                (string) $state['state'],
                (string) $state['total'],
                number_format((float) $state['share_pct'], 2),
                (string) $state['active'],
                (string) $state['distinct_parents'],
                $state['last_login'] === null ? '—' : (string) $state['last_login'],
            ], $states),
        );

        $this->line(sprintf(
            '缺失 parent 共 %d 個；以下前 %d 名涵蓋 %d 名孤兒會員（%s%%）。hierarchy_path 為 NULL 的代理 %d 個，其下線索無法計入',
            $missing['distinct_total'],
            count($missing['top']),
            $missing['covered_members'],
            number_format((float) $missing['covered_pct'], 2),
            $missing['agents_without_hierarchy_path'],
        ));

        if ($missing['top'] !== []) {
            $this->table(
                ['parent', '會員數', '啟用中', '最早建立', '最晚建立', '最後登入', '子代理數', '子代理組織數', '可推回組織'],
                array_map(static fn (array $row): array => [
                    (string) $row['parent'],
                    (string) $row['members'],
                    (string) $row['active'],
                    (string) $row['first_created'],
                    (string) $row['last_created'],
                    $row['last_login'] === null ? '—' : (string) $row['last_login'],
                    (string) $row['descendant_agents'],
                    (string) $row['descendant_organizations'],
                    $row['inferred_organization_id'] === null ? '—' : (string) $row['inferred_organization_id'],
                ], $missing['top']),
            );
        }

        $this->line(sprintf(
            '線索來源：member_agent %d 列、agent_migrate_logs（會員）%d 列——來源為 0 列時，下方「無線索」代表該路徑從未被驗證，不是推不回',
            $sources['member_agent_rows'],
            $sources['migrate_log_member_rows'],
        ));

        foreach ($hints as $cohort => $hint) {
            $this->line(sprintf(
                '%s：%d 名，member_agent 可推回 %d、搬移紀錄可推回 %d、任一線索 %d、無線索 %d',
                $cohort,
                $hint['total'],
                $hint['via_member_agent'],
                $hint['via_migrate_log'],
                $hint['any_hint'],
                $hint['no_hint'],
            ));
        }

        if ($unresolved === 0) {
            $this->info('所有會員都能沿解析鏈對到 currencies 主檔');

            return;
        }

        $this->error(sprintf(
            '有 %d 名會員（%s%%）解析不出主檔幣別，影子同步對他們只能寫 sentinel 0',
            $unresolved,
            $membersTotal === 0 ? '0.00' : number_format($unresolved / $membersTotal * 100, 2),
        ));
    }
}
