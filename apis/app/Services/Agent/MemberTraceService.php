<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Exceptions\ApiException;
use App\Models\Agent;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 會員追蹤（3963）。
 *
 * 風控用途：找出「同一人開多帳號」的關聯帳號。兩種互斥查詢：
 *  - 反查模式：給定用戶名 + 維度清單，取該會員的實際值（IP/密碼/電話/郵件/姓名/銀行）反查同值帳號。
 *  - 直接模式：直接輸入 IP/電話/郵件的值比對（不提供明文密碼查詢，4495；密碼只能由反查模式取得雜湊比對）。
 * 回傳每筆命中了哪些維度（供前端渲染風控色標）。
 *
 * 效能策略：
 *  - 主查詢只碰 members + agents（皆走索引），登入 IP 與銀行帳戶改「只對當頁 member_id 聚合」，
 *    絕不對 member_login_ip_logs（正式環境近兩千萬筆）做全表 JOIN 或 GROUP BY。
 *  - 一對多資料不進主 JOIN，避免主表列數膨脹導致分頁筆數失真。
 *  - 反查用 IP 先剔除熱門共用出口（公司 IP／電信 CGNAT，TPower 實測單一 IP 748 人共用）：
 *    既是效能防線（候選集爆炸＝冷快取下 ③④ 段上萬次隨機回表，實測 23s）也是雜訊防線（假關聯）。
 *  - 顯示用 IP 只取近期（全歷史聚合＝當頁 30 人 8,639 次散落回表，冷快取實測 6.4s）；
 *    但「與查詢條件撞到的 IP」必置頂顯示，維持風控可親眼驗證命中的保證。
 *  - 總筆數封頂（COUNT_CAP）：上萬命中代表條件太寬，精確計數沒有風控價值，不付全掃成本。
 */
class MemberTraceService
{
    /** 可篩選條件 => members 實際欄位（欄位名走白名單，值一律綁定參數） */
    private const CONDITION_FIELDS = [
        'password' => 'm.password_md5',
        'mobile' => 'm.mobile',
        'email' => 'm.email',
        // 真實姓名只比 first_name（有索引）。last_name 無索引，OR 兩欄會逼優化器全表掃
        // 99.8 萬列（TPower 實測每查恆定 2.2s）；而 last_name 有值的殘留舊資料全站僅 180 筆
        // （0.018%），為其付全表掃不值。取值端（resolveTraceConditions）仍會拿舊資料的
        // last_name 當「值」去比對別人的 first_name，僅放棄「比對別人的 last_name」。
        'realname' => 'm.first_name',
    ];

    /** 反查模式下，單一會員最多取幾個登入 IP（效能防護，見 loginIps()） */
    private const TRACE_IP_LIMIT = 20;

    /**
     * 單一 IP 的 log 列數超過此值視為熱門共用出口（公司 IP／電信 CGNAT），不參與反查。
     * TPower 實測：公司 IP 0.0.0.0 有 8,160 列、748 個會員共用——反查它只會
     * 撈出一整批無關帳號（假關聯），且候選集爆炸讓當頁回表在冷快取下衝到 20 秒以上。
     * 判定用 COUNT(*)（走 ip_address 索引免回表，毫秒級），不用 COUNT(DISTINCT member_id)（要回表）。
     */
    private const HOT_IP_ROW_THRESHOLD = 5000;

    /** 列表「登入 IP」欄最多顯示幾個（與查詢條件撞到的必列，之後以近期 IP 補滿） */
    private const DISPLAY_IP_LIMIT = 20;

    /**
     * 顯示用近期 IP 的掃描窗口：每人只回讀最近 N 筆 log 再去重。
     * 全歷史聚合是 23 秒事故的主因之一（當頁 30 人 8,639 次散落回表＝冷快取 6.4s）；
     * 行動用戶幾乎每列換 IP，60 筆窗口足以湊滿 20 個不重複。
     */
    private const RECENT_IP_SCAN_ROWS = 60;

    /** 總筆數封頂：數到此值即停（前端顯示「10,000+」），不為超寬條件付全量計數成本 */
    private const COUNT_CAP = 10000;

    /** 帳號快搜下拉最多回幾筆建議 */
    private const SUGGEST_LIMIT = 10;

    /** 反查模式未指定維度時的預設全維度（指紋不做） */
    private const TRACE_FIELDS = ['ip', 'password', 'mobile', 'email', 'realname', 'bank'];

    /** 反查對象本人的 member_id（反查模式下用於置頂本人；直接模式為 null） */
    private ?int $traceTargetId = null;

    /**
     * 本次查詢實際使用的 IP 條件（反查取得或直接輸入）。
     * 供顯示層做「撞到的 IP 置頂」與 hit_ip 判定（與當頁撞 IP 查詢同一趟，免 per-row EXISTS）。
     *
     * @var array<int, string>
     */
    private array $searchIps = [];

    /**
     * 兩種互斥模式（前端保證只送一組）：
     *  - 反查模式（上組）：帶 username + traceFields，先取該會員的實際值，
     *    再用這些值反查「還有誰跟他一樣」。
     *  - 直接模式（下組）：帶 conditions（IP/電話/郵件的直接輸入值）。
     * AND 跨組作用：勾選＝所有條件同時成立(AND)，不勾＝任一成立(OR)。
     *
     * @param  array<string, string>  $conditions  下組直接輸入值（key 對應 CONDITION_FIELDS，另含 ip；不含 password）
     * @param  array<int, string>  $traceFields  上組要反查的維度清單（ip/password/mobile/email/realname/bank）
     * @return LengthAwarePaginator<int, object>
     */
    public function search(Agent $agent, string $username, array $conditions, array $traceFields, bool $useAnd, int $page, int $perPage): LengthAwarePaginator
    {
        $conditions = array_filter($conditions, fn ($value) => filled($value));
        $isTraceMode = $username !== '';

        // 反查模式：填了 username 即啟用；未選維度時預設全維度反查
        if ($isTraceMode) {
            $fields = count($traceFields) > 0 ? $traceFields : self::TRACE_FIELDS;
            $conditions = $this->resolveTraceConditions($agent, $username, $fields);
        }

        // 反查模式下該會員沒有任何可反查的值（帳號存在但 IP/密碼/電話…全空）→ 回空列表，不報錯
        if ($isTraceMode && count($conditions) === 0) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }

        // 直接模式完全沒填條件（防呆，前端已擋）
        if (count($conditions) === 0) {
            throw new ApiException('at least one search condition is required');
        }

        $this->searchIps = isset($conditions['ip']) ? array_values(array_filter((array) $conditions['ip'])) : [];

        $query = $this->baseQuery($this->scopePath($agent));
        $this->applyConditions($query, $conditions, $useAnd);

        // 封頂計數：total 為 COUNT_CAP+1 即代表「超過 COUNT_CAP」，前端顯示 10,000+
        $total = DB::query()->fromSub(
            (clone $query)->select('m.id')->limit(self::COUNT_CAP + 1),
            't',
        )->count();

        $query->select($this->baseColumns());
        $this->applyHitMarks($query, $conditions);

        // 反查模式：被查的本人置頂（第一列），其餘照 id 倒序
        if ($this->traceTargetId !== null) {
            $query->orderByRaw('CASE WHEN m.id = ? THEN 0 ELSE 1 END', [$this->traceTargetId]);
        }

        $rows = $query
            ->orderBy('m.id', 'desc')
            ->forPage($page, $perPage)
            ->get();

        $this->attachRelations($rows);

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /**
     * 帳號快搜建議：前綴比對回符合的 username 清單（上限 10 筆），限當前代理可視範疇。
     *
     * 前綴比對（keyword%）走 members_username_unique 索引；限筆數避免打字時的即時查詢過重。
     *
     * @return array<int, string>
     */
    public function suggestUsernames(Agent $agent, string $keyword): array
    {
        if ($keyword === '') {
            return [];
        }

        $scopePath = $this->scopePath($agent);

        return $this->baseQuery($scopePath)
            ->where('m.username', 'like', $keyword . '%')
            ->orderBy('m.username')
            ->limit(self::SUGGEST_LIMIT)
            ->pluck('m.username')
            ->all();
    }

    /**
     * 可視範疇的 hierarchy_path 前綴；admin(id=1) 與其子帳號(host_id=1) 回 null 表不限制。
     * 代理子帳號(host_id!=0) 看其上層代理的範疇（比照 MemberActivityFilterService）。
     */
    private function scopePath(Agent $agent): ?string
    {
        if ($agent->id == 1 || $agent->host_id == 1) {
            return null;
        }

        $path = $agent->hierarchy_path;
        if ($agent->host_id != 0) {
            $upperAgent = Agent::where('id', $agent->host_id)->first();
            $path = $upperAgent->hierarchy_path ?? $path;
        }

        return $path;
    }

    /**
     * agent 範疇 base。
     *
     * status=0 為系統佔位帳號（SendValidationCodeService 建立，共用同一組固定密碼），
     * 會在同密碼比對中形成假群組，故一律排除。
     */
    private function baseQuery(?string $scopePath): Builder
    {
        $query = DB::table('members as m')
            ->join('agents as a', 'a.id', '=', 'm.parent')
            ->where('m.status', '!=', 0);

        // fail-closed：hierarchy_path 為空時不可進 LIKE（會變全站可見），退化成只看自己
        if ($scopePath !== null) {
            $query->where(function (Builder $q) use ($scopePath) {
                if ($scopePath === '') {
                    $q->whereRaw('1 = 0');

                    return;
                }
                $q->where('a.hierarchy_path', $scopePath)
                    ->orWhere('a.hierarchy_path', 'like', $scopePath . '/%');
            });
        }

        return $query;
    }

    /**
     * 反查模式：取 username 對應會員的實際值，依 traceFields 展開成比對條件。
     *
     * 例：username=frank、traceFields=[ip,password] → 取 frank 的登入 IP 與 password_md5，
     * 再拿這些值反查「還有誰跟他一樣」。frank 本人也會被列出（值相符）。
     *
     * @param  array<int, string>  $traceFields
     * @return array<string, string>
     */
    private function resolveTraceConditions(Agent $agent, string $username, array $traceFields): array
    {
        // 反查目標必須在自己的下線範疇內（4495）：取出的 IP／密碼雜湊／電話／郵件／銀行帳號都是敏感值，
        // 不限範疇等於任何有權限的帳號都能把別線會員的資料當查詢條件吐出來
        $member = $this->baseQuery($this->scopePath($agent))
            ->where('m.username', $username)
            ->select('m.id', 'm.password_md5', 'm.mobile', 'm.email', 'm.first_name', 'm.last_name')
            ->first();

        // 帳號不存在（或不在可視範疇）→ 回空條件，上層回空列表，不報錯
        if ($member === null) {
            return [];
        }

        // 記住反查對象本人，供排序置頂（比照 ttn：被查的人排第一列）
        $this->traceTargetId = (int) $member->id;

        $conditions = [];
        foreach ($traceFields as $field) {
            $value = match ($field) {
                // IP／銀行為一對多，反查取該會員全部值（見 applyIpList/applyBankList）
                'ip' => $this->loginIps($member->id),
                'password' => $member->password_md5,
                'mobile' => $member->mobile,
                'email' => $member->email,
                // 正牌欄位為 first_name，空才 fallback last_name（舊資料）；不串接以免兩欄都有值時黏字
                'realname' => trim((string) $member->first_name) !== '' ? trim((string) $member->first_name) : trim((string) $member->last_name),
                'bank' => $this->bankAccountNos($member->id),
                default => null,
            };
            if (filled($value)) {
                $conditions[$field] = $value;
            }
        }

        return $conditions;
    }

    /**
     * 取該會員最近 N 個登入 IP（去重＋剔除熱門共用出口），供反查用。
     *
     * 上限 TRACE_IP_LIMIT：正式庫實測單一會員最多 5,587 個不重複 IP（平均 18），
     * 若全部展開成 OR 打 member_login_ip_logs(近 2000 萬筆)會拖垮 DB。
     * 熱門剔除見 HOT_IP_ROW_THRESHOLD——共用出口反查出的是「撞過同一個電信出口的人」
     * 而非「同一人」，效能與訊號品質一起處理。
     *
     * @return array<int, string>
     */
    private function loginIps(int $memberId): array
    {
        // 取最近 N 個不重複 IP（ip_address 完整字串，通用 IPv4/IPv6）
        $ips = DB::table('member_login_ip_logs')
            ->where('member_id', $memberId)
            ->whereNotNull('ip_address')
            ->groupBy('ip_address')
            ->orderByDesc(DB::raw('MAX(id)'))
            ->limit(self::TRACE_IP_LIMIT)
            ->pluck('ip_address')
            ->all();

        return $this->rejectHotIps($ips);
    }

    /**
     * 剔除熱門共用 IP。單一 GROUP BY 走 ip_address 索引免回表（covering），毫秒級。
     *
     * @param  array<int, string>  $ips
     * @return array<int, string>
     */
    private function rejectHotIps(array $ips): array
    {
        if (count($ips) === 0) {
            return [];
        }

        $hot = DB::table('member_login_ip_logs')
            ->whereIn('ip_address', $ips)
            ->groupBy('ip_address')
            ->havingRaw('COUNT(*) > ?', [self::HOT_IP_ROW_THRESHOLD])
            ->pluck('ip_address')
            ->all();

        if (count($hot) === 0) {
            return $ips;
        }

        return array_values(array_diff($ips, $hot));
    }

    /**
     * 取該會員全部啟用中的銀行帳號（已 TRIM、去重），供反查用。
     *
     * @return array<int, string>
     */
    private function bankAccountNos(int $memberId): array
    {
        return DB::table('member_bank_accounts')
            ->where('member_id', $memberId)
            ->where('status', 1)
            ->selectRaw('DISTINCT TRIM(account_no) as account_no')
            ->pluck('account_no')
            ->all();
    }

    /**
     * 依 use_and 套用條件：true 為全部成立，false 為任一成立（整組包在同一 closure 內）。
     *
     * @param  array<string, string>  $conditions
     */
    private function applyConditions(Builder $query, array $conditions, bool $useAnd): void
    {
        if (count($conditions) === 0) {
            return;
        }

        if ($useAnd) {
            foreach ($conditions as $key => $value) {
                $this->applyOne($query, $key, $value, false);
            }

            return;
        }

        // OR 模式不用 orWhere 串接：OR 混多個 IN 子查詢會讓優化器放棄索引改全表掃
        // members（TPower 實測全維度反查恆定 5.3s，與冷熱無關）。改為各維度先以
        // 自己的索引取 member_id、UNION ALL 匯集（半連接自帶去重）再回 members，
        // 全表掃消失（同場景 5.3s → 亞秒）。
        $union = null;
        foreach ($conditions as $key => $value) {
            $source = $this->idSourceFor($key, $value);
            if ($source === null) {
                continue;
            }
            $union = $union === null ? $source : $union->unionAll($source);
        }

        // 所有維度都無有效值 → 條件不成立（維持與舊版 orWhereRaw('1=0') 相同語意）
        if ($union === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('m.id', fn (Builder $sub) => $sub->select('member_id')->fromSub($union, 'u'));
    }

    /**
     * OR 模式下單一維度的 member_id 來源子查詢（各自走索引；無有效值回 null）。
     */
    private function idSourceFor(string $key, string|array $value): ?Builder
    {
        if ($key === 'ip') {
            $ips = array_values(array_filter((array) $value));

            return $ips === [] ? null : DB::table('member_login_ip_logs')
                ->whereIn('ip_address', $ips)
                ->select('member_id');
        }

        if ($key === 'bank') {
            $accountNos = array_values(array_filter((array) $value));

            return $accountNos === [] ? null : DB::table('member_bank_accounts')
                ->where('status', 1)
                ->whereIn(DB::raw('TRIM(account_no)'), $accountNos)
                ->select('member_id');
        }

        $col = self::CONDITION_FIELDS[$key] ?? null;
        if ($col === null || !is_string($value) || $value === '') {
            return null;
        }

        // CONDITION_FIELDS 存的是主查詢別名欄位（m.xxx），此處查 members 本表
        return DB::table('members')
            ->where(str_replace('m.', '', $col), $value)
            ->selectRaw('id as member_id');
    }

    /**
     * 單一條件。$col 取自白名單常數，值一律以綁定參數帶入。
     */
    /**
     * @param  string|array<int, string>  $value  一般欄位為字串；ip/bank 反查模式可為多值陣列
     */
    private function applyOne(Builder $query, string $key, string|array $value, bool $isOr): void
    {
        if ($key === 'ip') {
            $this->applyIpList($query, array_values(array_filter((array) $value)), $isOr);

            return;
        }

        if ($key === 'bank') {
            $this->applyBankList($query, array_values(array_filter((array) $value)), $isOr);

            return;
        }

        $col = self::CONDITION_FIELDS[$key] ?? null;
        if ($col === null) {
            return;
        }

        $isOr ? $query->orWhere($col, $value) : $query->where($col, $value);
    }

    /**
     * IP 反查：命中「登入 IP（ip_address）曾等於清單中任一個」的會員。
     * ip_address 為完整字串（通用 IPv4/IPv6）；不限時間範圍。
     *
     * @param  array<int, string>  $ips
     */
    private function applyIpList(Builder $query, array $ips, bool $isOr): void
    {
        if (count($ips) === 0) {
            // 無 IP → 條件不成立
            $isOr ? $query->orWhereRaw('1 = 0') : $query->whereRaw('1 = 0');

            return;
        }

        $method = $isOr ? 'orWhereIn' : 'whereIn';
        $query->{$method}('m.id', function ($sub) use ($ips) {
            $sub->select('member_id')->from('member_login_ip_logs')
                ->whereIn('ip_address', $ips);
        });
    }

    /**
     * 銀行帳號反查：命中「啟用中帳號等於清單中任一個」的會員（account_no 去頭尾空白）。
     *
     * @param  array<int, string>  $accountNos
     */
    private function applyBankList(Builder $query, array $accountNos, bool $isOr): void
    {
        if (count($accountNos) === 0) {
            $isOr ? $query->orWhereRaw('1 = 0') : $query->whereRaw('1 = 0');

            return;
        }

        $method = $isOr ? 'orWhereIn' : 'whereIn';
        $query->{$method}('m.id', function ($sub) use ($accountNos) {
            $sub->select('member_id')->from('member_bank_accounts')
                ->where('status', 1)
                ->whereIn(DB::raw('TRIM(account_no)'), $accountNos);
        });
    }

    /**
     * 主查詢欄位。
     *
     * @return array<int, mixed>
     */
    private function baseColumns(): array
    {
        return [
            'm.id',
            'm.username',
            'a.username as agent_username',
            'm.first_name',
            'm.last_name',
            'm.mobile',
            'm.email',
            'm.created_at',
            'm.last_login',
            DB::raw('INET_NTOA(m.last_login_ip) as last_login_ip'),
        ];
    }

    /**
     * 命中標記：以 CASE WHEN / EXISTS 與主查詢同一趟算出，不另開查詢。
     * 每個維度獨立判定（OR 模式下某列可能因其他條件入列而未命中該維度，不可直接標 1）。
     * 欄位名取自白名單常數，值一律綁定參數。
     *
     * IP 命中不在此算：舊版的 per-row EXISTS 對每個候選列掃該會員的 log（散落大表，
     * 冷快取下是 23 秒事故的成因之一），改由 attachRelations() 以「當頁撞 IP」單一
     * 索引查詢在 PHP 端判定（與顯示置頂共用同一趟，零額外成本）。
     *
     * @param  array<string, string|array<int, string>>  $conditions
     */
    private function applyHitMarks(Builder $query, array $conditions): void
    {
        // 一般單值欄位（password/mobile/email/realname，皆為索引欄位等值比對）
        foreach (self::CONDITION_FIELDS as $key => $col) {
            if (isset($conditions[$key]) && is_string($conditions[$key])) {
                $query->selectRaw("CASE WHEN {$col} = ? THEN 1 ELSE 0 END as hit_{$key}", [$conditions[$key]]);

                continue;
            }

            $query->selectRaw("0 as hit_{$key}");
        }

        // 銀行帳號命中：子查詢比對（值可為單值或反查多值）；以 member_id 關聯探測，
        // 每列僅讀該會員自己的少數帳戶列，成本可控
        $bankNos = isset($conditions['bank']) ? array_values(array_filter((array) $conditions['bank'])) : [];
        if (count($bankNos) > 0) {
            $placeholders = implode(',', array_fill(0, count($bankNos), '?'));
            $query->selectRaw(
                'EXISTS(SELECT 1 FROM member_bank_accounts mba WHERE mba.member_id = m.id'
                . " AND mba.status = 1 AND TRIM(mba.account_no) IN ({$placeholders})) as hit_bank",
                $bankNos,
            );
        } else {
            $query->selectRaw('0 as hit_bank');
        }
    }

    /**
     * 只對當頁 member_id 聚合登入 IP 與銀行帳戶（吃 member_id 索引，不做全表聚合）。
     *
     * @param  Collection<int, object>  $rows
     */
    private function attachRelations(Collection $rows): void
    {
        $ids = $rows->pluck('id')->all();

        if (count($ids) === 0) {
            return;
        }

        $matchedMap = $this->fetchMatchedIps($ids);
        $recentMap = $this->fetchRecentIps($ids);
        $countMap = $this->fetchLoginLogCounts($ids);
        $bankMap = $this->fetchBankAccounts($ids);

        $rows->transform(function (object $row) use ($matchedMap, $recentMap, $countMap, $bankMap) {
            $id = (int) $row->id;
            $matched = $matchedMap[$id] ?? [];

            // 撞到的 IP 置頂（風控必須能親眼驗證命中，即使該 IP 已不在近期清單內），
            // 之後以近期 IP 補滿到 DISPLAY_IP_LIMIT
            $ips = $matched;
            foreach ($recentMap[$id] ?? [] as $ip) {
                if (count($ips) >= max(self::DISPLAY_IP_LIMIT, count($matched))) {
                    break;
                }
                if (!in_array($ip, $ips, true)) {
                    $ips[] = $ip;
                }
            }

            $row->login_ips = $ips;
            $row->matched_login_ips = $matched;
            $row->login_log_total = $countMap[$id] ?? 0;
            // IP 命中改由撞 IP 查詢判定（見 applyHitMarks docblock）
            $row->hit_ip = count($matched) > 0 ? 1 : 0;
            $row->bank_accounts = $bankMap[$id] ?? [];
            // 反查對象本人不標風控（跟自己當然全中，標記無意義）
            $row->is_trace_target = $this->traceTargetId !== null && $id === $this->traceTargetId;

            return $row;
        });
    }

    /**
     * 當頁會員與查詢 IP 條件「撞到」的 IP（去重）。
     * member_id 與 ip_address 皆有索引且兩側值域都小（30 × 20），毫秒級。
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<int, string>>
     */
    private function fetchMatchedIps(array $ids): array
    {
        if (count($this->searchIps) === 0) {
            return [];
        }

        $rows = DB::table('member_login_ip_logs')
            ->whereIn('member_id', $ids)
            ->whereIn('ip_address', $this->searchIps)
            ->groupBy('member_id', 'ip_address')
            ->select('member_id', 'ip_address')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->member_id][] = $row->ip_address;
        }

        return $map;
    }

    /**
     * 近期登入 IP（去重、新到舊）。每人只回讀最近 RECENT_IP_SCAN_ROWS 筆再於 PHP 去重，
     * 取代舊版「全歷史 GROUP BY」（當頁 30 人 8,639 次散落回表＝冷快取 6.4s）。
     * 以 UNION ALL 一趟送出，避免迴圈內逐人查詢（N+1）。
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<int, string>>
     */
    private function fetchRecentIps(array $ids): array
    {
        $union = null;
        foreach ($ids as $id) {
            $sub = DB::table('member_login_ip_logs')
                ->where('member_id', $id)
                ->whereNotNull('ip_address')
                ->orderByDesc('id')
                ->limit(self::RECENT_IP_SCAN_ROWS)
                ->select('member_id', 'ip_address');

            $union = $union === null ? $sub : $union->unionAll($sub);
        }

        $map = [];
        foreach ($union->get() as $row) {
            $id = (int) $row->member_id;
            if (!in_array($row->ip_address, $map[$id] ?? [], true)) {
                $map[$id][] = $row->ip_address;
            }
        }

        return $map;
    }

    /**
     * 每人登入紀錄總筆數（原始列數，非去重 IP 數——去重計數需回表全掃，不值）。
     * COUNT(*) GROUP BY member_id 走 member_id 索引免回表（covering）。
     *
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function fetchLoginLogCounts(array $ids): array
    {
        return DB::table('member_login_ip_logs')
            ->whereIn('member_id', $ids)
            ->groupBy('member_id')
            ->select('member_id', DB::raw('COUNT(*) as total'))
            ->pluck('total', 'member_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * 銀行帳戶清單（僅啟用中）。bank_id 關聯 banks.id。
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<int, string>>
     */
    private function fetchBankAccounts(array $ids): array
    {
        $rows = DB::table('member_bank_accounts as mba')
            ->leftJoin('banks as b', 'b.id', '=', 'mba.bank_id')
            ->whereIn('mba.member_id', $ids)
            ->where('mba.status', 1)
            ->orderBy('mba.sort')
            ->orderBy('mba.id')
            ->select(
                'mba.member_id',
                'b.name as bank_name',
                DB::raw('TRIM(mba.account_no) as account_no'),
            )
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->member_id][] = filled($row->bank_name)
                ? $row->bank_name . ' - ' . $row->account_no
                : (string) $row->account_no;
        }

        return $map;
    }
}
