<?php

declare(strict_types=1);

/**
 * MR 合併前核准的判定邏輯。只放不碰網路的純函式，供 approval-check.php 與
 * approval-check-test.php 共用，規則改了跑測試就知道有沒有改壞。
 *
 * 定位：這套檢查是用來防疏忽（忘了找人審、審完又偷推 commit），不防刻意繞過。
 * CI 設定、名單檔與這支腳本都在同一個 repo，作者在自己的 MR 裡改掉它們，那張 MR 就會照改過的版本檢查；
 * 社群版沒有辦法在同一個 repo 內擋住這件事，但修改會留在 MR 的 diff 與 git 歷史。
 *
 * 判定規則（與說明頁「MR 審批制度」一致）：
 * - 核准紀錄一律從 MR 的系統留言讀：按核准鈕會留下 "approved this merge request"，
 *   取消核准留下 "unapproved this merge request"。每個人只看最後一次動作。
 *   不另建 token 讀核准 API，是因為 CI_JOB_TOKEN 讀不到核准 API、但讀得到留言。
 * - 有效核准必須同時符合：最後一次動作是核准、帳號在動到的領域的審核人名單內、
 *   不是 MR 作者、嚴格晚於最後一次推 commit（同一時間戳不算，無法判斷先後時一律從嚴）。
 * - 「最後一次推 commit」取系統留言 "added N commit(s)" 的時間；MR 開單後沒再推過就用 MR 建立時間。
 *   那則留言的作者就是推 commit 的人，提醒審核人重審時用來說明是誰推的。
 * - 失效的核准：最後一次動作是核准、在名單內、不是作者，但時間不晚於最後一次推 commit。
 *   GitLab 畫面上仍顯示「已核准」，實際已不算數；提醒重新核准的 job 只 @ 這些人。
 * - 留言依建立時間排序，同一時間戳再依留言 ID 排序（ID 遞增），不依賴 API 回傳的順序。
 *   不用 pipeline 建立時間，因為從 MR 頁手動重跑 pipeline 會讓既有核准全部被誤判成過期。
 * - 帳號比對不分大小寫（名單寫 RB、GitLab 回傳 rb 都算同一人）。
 * - 領域用路徑、標籤或兩者一起定義：MR 動到領域的任一路徑，或貼了領域的任一標籤，就算動到這個領域。
 *   沒動到任何領域的 MR 不需要核准。標籤用在路徑分不出來的改動（例如讓 skill 原本的觸發說法失效，檔案仍是 SKILL.md）。
 * - 核准範圍只列「出錯會讓別人的 Claude 壞掉、連錯服務或裝不起來」的路徑，以及決定誰能放行上線的團隊名單
 *   （清單在 .gitlab/approval-rules.json）。
 *   skill 內文、commands 與 agents 定義這類給 Claude 讀的文字不列入，出錯可以事後修。
 *   政策性的 skill 內文（例如提交規則）也不列入：機器分不出哪段文字算政策，只能靠作者自己貼標籤，
 *   忘了貼就等於沒審。
 * - 讓原本的觸發說法失效或移除 skill，靠作者貼 breaking 標籤認定，不由程式比對 SKILL.md 的 description：
 *   比對不會漏，但要多維護一段比對邏輯；漏貼的情況變多再補。只新增觸發說法的改動不算，原本的講法照樣能用。
 * - 動到多個領域時，任一領域的審核人核准即通過。這是刻意的輕量做法，代價是跨領域 MR
 *   可能由非主要領域的人核准；要不要改成每個領域各一位，看實際使用情況再定。
 */

const APPROVAL_NOTE_APPROVED   = 'approved this merge request';
const APPROVAL_NOTE_UNAPPROVED = 'unapproved this merge request';

/**
 * 讀取並驗證 .gitlab/approval-rules.json。格式不對直接丟例外，不讓壞掉的名單默默放行。
 *
 * @return array{bypass_label: string, domains: list<array{name: string, paths: list<string>, labels: list<string>, reviewers: list<string>}>}
 */
function approvalLoadRules(string $file): array
{
    $raw = @file_get_contents($file);
    if ($raw === false) {
        throw new RuntimeException("讀不到審核名單：{$file}");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("審核名單不是合法的 JSON：{$file}");
    }

    $label = $data['bypass_label'] ?? null;
    if (!is_string($label) || $label === '') {
        throw new RuntimeException('審核名單缺少 bypass_label（緊急放行標籤名稱）');
    }

    $domains = $data['domains'] ?? null;
    if (!is_array($domains) || $domains === []) {
        throw new RuntimeException('審核名單缺少 domains');
    }

    $clean = [];
    foreach ($domains as $i => $domain) {
        $name      = $domain['name'] ?? null;
        $paths     = $domain['paths'] ?? [];
        $labels    = $domain['labels'] ?? [];
        $reviewers = $domain['reviewers'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new RuntimeException("第 " . ($i + 1) . " 個領域缺少 name");
        }
        foreach (['paths' => $paths, 'labels' => $labels] as $key => $list) {
            if (!is_array($list) || array_filter($list, static fn ($v): bool => !is_string($v) || $v === '') !== []) {
                throw new RuntimeException("領域「{$name}」的 {$key} 必須是字串陣列");
            }
        }
        // 兩個都空的領域永遠不會被動到，等於名單寫了卻沒有作用
        if ($paths === [] && $labels === []) {
            throw new RuntimeException("領域「{$name}」的 paths 與 labels 至少要有一個");
        }
        // 至少兩位：作者本人是審核人時，還要有另一位能核准
        if (!is_array($reviewers) || count($reviewers) < 2 || array_filter($reviewers, static fn ($r): bool => !is_string($r) || $r === '') !== []) {
            throw new RuntimeException("領域「{$name}」的 reviewers 至少要有兩位");
        }
        $clean[] = ['name' => $name, 'paths' => array_values($paths), 'labels' => array_values($labels), 'reviewers' => array_values($reviewers)];
    }

    return ['bypass_label' => $label, 'domains' => $clean];
}

/**
 * 路徑比對：以 / 結尾代表整個目錄（前綴比對）；"*" 代表整個 repo；其餘用 fnmatch
 * （例如 app/Models/EventV2*.php）。fnmatch 不加 FNM_PATHNAME，讓 * 也能跨目錄。
 * 以 / 結尾的寫法照字面比對、不展開萬用字元。路徑中間帶萬用字元的目錄（例如每個 plugin 的 bin 目錄），
 * 結尾要再加一個星號寫成 fnmatch 形式，否則比對不到任何檔案；寫法見 .gitlab/approval-rules.json。
 */
function approvalPathMatches(string $pattern, string $path): bool
{
    if ($pattern === '*') {
        return true;
    }
    if (str_ends_with($pattern, '/')) {
        return str_starts_with($path, $pattern);
    }

    return fnmatch($pattern, $path);
}

/**
 * 需不需要取得實際的變更檔案清單。所有領域都是 "*"（整個 repo）時不需要；
 * 只要有一個領域列了具體路徑就要，否則那個領域的審核人會被漏算。
 */
function approvalNeedsChangedPaths(array $rules): bool
{
    foreach ($rules['domains'] as $domain) {
        if ($domain['paths'] !== ['*']) {
            return true;
        }
    }

    return false;
}

/**
 * 找出這張 MR 動到哪些領域：變更檔案符合領域的路徑，或 MR 貼了領域的標籤。
 *
 * @param list<string> $changedPaths
 * @param list<string> $labels       MR 貼的標籤名稱
 * @return list<array{name: string, paths: list<string>, reviewers: list<string>}>
 */
function approvalTouchedDomains(array $rules, array $changedPaths, array $labels = []): array
{
    $touched = [];
    foreach ($rules['domains'] as $domain) {
        if (array_intersect($domain['labels'] ?? [], $labels) !== []) {
            $touched[] = $domain;
            continue;
        }
        foreach ($changedPaths as $path) {
            foreach ($domain['paths'] as $pattern) {
                if (approvalPathMatches($pattern, $path)) {
                    $touched[] = $domain;
                    continue 3;
                }
            }
        }
    }

    return $touched;
}

/**
 * 判定核准是否有效。
 *
 * @param list<array<string, mixed>> $notes      MR 留言（GitLab notes API 原樣）
 * @param list<string>               $reviewers  動到的領域的審核人聯集
 * @param string|null                $until      只看這個時間點以前的留言（合併後檢查傳 merged_at）
 * @return array{approved: bool, approvers: list<string>, since: string, reset_by_push: bool, stale: list<string>, stale_at: array<string, string>, since_by: string}
 *         reset_by_push：MR 開單後有沒有再推過 commit（有的話，早於那次推送的核准都失效）
 *         stale：核准已因推 commit 失效的帳號（GitLab 回傳的大小寫，已排序）
 *         stale_at：stale 每個帳號按核准的時間（帳號 → ISO 8601，UTC）
 *         since_by：最後一次推 commit 的人；開單後沒再推過就是空字串
 */
function approvalEvaluate(array $notes, string $author, array $reviewers, string $mrCreatedAt, ?string $until = null): array
{
    // 作者空白時「排除作者」會失效，作者自己的核准會被當成別人的
    if ($author === '' || $mrCreatedAt === '') {
        throw new RuntimeException('MR 缺少作者或建立時間，無法判定核准');
    }
    $untilAt   = $until !== null ? new DateTimeImmutable($until) : null;
    $createdAt = new DateTimeImmutable($mrCreatedAt);
    $sinceAt   = $createdAt;
    $sinceBy   = '';
    $allowed = array_map('strtolower', $reviewers);
    $authorLc = strtolower($author);

    $sorted = array_values(array_filter($notes, static fn ($n): bool => is_array($n) && ($n['system'] ?? false) === true));
    foreach ($sorted as $note) {
        // 缺時間會被當成「現在」而看起來像最新的核准，寧可整個檢查失敗
        if (!is_string($note['created_at'] ?? null) || $note['created_at'] === '') {
            throw new RuntimeException('系統留言缺少 created_at，無法判斷核准先後');
        }
    }
    usort($sorted, static function (array $a, array $b): int {
        $byTime = new DateTimeImmutable((string) ($a['created_at'] ?? '')) <=> new DateTimeImmutable((string) ($b['created_at'] ?? ''));

        return $byTime !== 0 ? $byTime : ((int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
    });

    // 每個人的最後一次動作；key 用小寫帳號。已排序，後面的直接覆蓋前面的
    $last = [];
    foreach ($sorted as $note) {
        $at = new DateTimeImmutable((string) ($note['created_at'] ?? ''));
        if ($untilAt !== null && $at > $untilAt) {
            continue;
        }
        $body = trim((string) ($note['body'] ?? ''));

        if (preg_match('/^added \d+ commits?\b/', $body) === 1) {
            if ($at > $sinceAt) {
                $sinceAt = $at;
                $sinceBy = (string) ($note['author']['username'] ?? '');
            }
            continue;
        }

        if ($body !== APPROVAL_NOTE_APPROVED && $body !== APPROVAL_NOTE_UNAPPROVED) {
            continue;
        }
        $user = (string) ($note['author']['username'] ?? '');
        if ($user === '') {
            continue;
        }
        $last[strtolower($user)] = ['user' => $user, 'at' => $at, 'approved' => $body === APPROVAL_NOTE_APPROVED];
    }

    $approvers = [];
    $stale     = [];
    $staleAt   = [];
    foreach ($last as $key => $action) {
        if (!$action['approved'] || $key === $authorLc || !in_array($key, $allowed, true)) {
            continue;
        }
        if ($action['at'] <= $sinceAt) {
            $stale[] = $action['user'];
            $staleAt[$action['user']] = $action['at']->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
            continue;
        }
        $approvers[] = $action['user'];
    }
    sort($approvers);
    sort($stale);
    ksort($staleAt);

    return [
        'approved'  => $approvers !== [],
        'approvers' => $approvers,
        'since'         => $sinceAt->format('Y-m-d H:i:s') . ' UTC',
        'reset_by_push' => $sinceAt > $createdAt,
        'stale'         => $stale,
        'stale_at'      => $staleAt,
        'since_by'      => $sinceBy,
    ];
}

/**
 * MR 貼的標籤名稱。labels 可能是字串陣列，也可能是帶 name 的物件陣列（API 參數不同時）。
 *
 * @return list<string>
 */
function approvalLabelNames(array $mr): array
{
    $names = [];
    foreach ((array) ($mr['labels'] ?? []) as $item) {
        $name = is_array($item) ? ($item['name'] ?? null) : $item;
        if (is_string($name) && $name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

/** MR 有沒有貼某個標籤。 */
function approvalHasLabel(array $mr, string $label): bool
{
    return in_array($label, approvalLabelNames($mr), true);
}

/**
 * 動到的領域的審核人聯集（保留名單順序、去重、不分大小寫）。
 *
 * @param list<array{reviewers: list<string>}> $domains
 * @return list<string>
 */
function approvalReviewerUnion(array $domains): array
{
    $seen = [];
    foreach ($domains as $domain) {
        foreach ($domain['reviewers'] as $reviewer) {
            $seen[strtolower($reviewer)] ??= $reviewer;
        }
    }

    return array_values($seen);
}

/**
 * 檢查沒過時要給作者看的「可以找誰」：每個領域列出作者以外的審核人，第一位標為建議先找。
 *
 * @param list<array{name: string, reviewers: list<string>}> $domains
 * @return list<string>
 */
function approvalWhoToAsk(array $domains, string $author): array
{
    $lines = [];
    foreach ($domains as $domain) {
        $names = array_values(array_filter(
            $domain['reviewers'],
            static fn (string $r): bool => strtolower($r) !== strtolower($author),
        ));
        $first = array_shift($names);
        $text  = $first !== null ? "{$first}（建議先找）" : '（名單上只剩作者本人，請更新名單）';
        if ($names !== []) {
            $text .= '、' . implode('、', $names);
        }
        $lines[] = "  ・{$domain['name']} → {$text}";
    }

    return $lines;
}

/**
 * 核准失效提醒留言尾端的隱藏標記，用來判斷同一次推送是不是已經提醒過。
 * 「提醒重新核准」與排程的 MR 介入通知共用：排程先留言，「提醒重新核准」就不再留；
 * 「提醒重新核准」先留言，排程只補 @ 它沒提醒到的人。
 */
function staleMarker(string $sha): string
{
    return "<!-- approval-stale: {$sha} -->";
}

/** MR 留言裡有沒有人工或機器人留言（非系統留言）帶著這個推送的核准失效標記。 */
function staleNoticeExists(array $notes, string $sha): bool
{
    $marker = staleMarker($sha);
    foreach ($notes as $note) {
        if (is_array($note) && ($note['system'] ?? false) !== true && str_contains((string) ($note['body'] ?? ''), $marker)) {
            return true;
        }
    }

    return false;
}
