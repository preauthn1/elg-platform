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
 * - 「最後一次推 commit」取系統留言 "added N commit(s)" 的時間；MR 開單後沒再推過就用 MR 建立時間
 *   （共用函式 gateLastPushAt()，在 .gitlab-ci/lib/merge-gate.php）。
 * - 留言依建立時間排序，同一時間戳再依留言 ID 排序（ID 遞增），不依賴 API 回傳的順序。
 *   不用 pipeline 建立時間，因為從 MR 頁手動重跑 pipeline 會讓既有核准全部被誤判成過期。
 * - 帳號比對不分大小寫（名單寫 RB、GitLab 回傳 rb 都算同一人）。
 * - 動到審核路徑的 PHP 檔若只改了註解或排版（修改既有檔案才算），不列入審核，見 approvalPhpLogicUnchanged()。
 * - 動到多個領域時，任一領域的審核人核准即通過。這是刻意的輕量做法，代價是跨領域 MR
 *   可能由非主要領域的人核准；要不要改成每個領域各一位，看實際使用情況再定。
 * - 緊急放行：沒有有效核准、但來源分支以 hotfix/ 開頭時放行，合併後發 Slack 提醒審核人在 1 個工作天內補審，
 *   見 approvalHotfixBypass()。只認分支名，因為分支名在開分支時就定了、每個人都看得到；標籤事後隨手就能貼上。
 */
require_once __DIR__ . '/../../lib/merge-gate.php';

const APPROVAL_NOTE_APPROVED = 'approved this merge request';
const APPROVAL_NOTE_UNAPPROVED = 'unapproved this merge request';

/**
 * 讀取並驗證 .gitlab/approval-rules.json。格式不對直接丟例外，不讓壞掉的名單默默放行。
 *
 * @return array{domains: list<array{name: string, paths: list<string>, reviewers: list<string>}>}
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

    $domains = $data['domains'] ?? null;
    if (!is_array($domains) || $domains === []) {
        throw new RuntimeException('審核名單缺少 domains');
    }

    $clean = [];
    foreach ($domains as $i => $domain) {
        $name = $domain['name'] ?? null;
        $paths = $domain['paths'] ?? null;
        $reviewers = $domain['reviewers'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new RuntimeException('第 ' . ($i + 1) . ' 個領域缺少 name');
        }
        if (!is_array($paths) || $paths === [] || array_filter($paths, static fn ($p): bool => !is_string($p) || $p === '') !== []) {
            throw new RuntimeException("領域「{$name}」的 paths 必須是非空字串陣列");
        }
        // 至少兩位：作者本人是審核人時，還要有另一位能核准
        if (!is_array($reviewers) || count($reviewers) < 2 || array_filter($reviewers, static fn ($r): bool => !is_string($r) || $r === '') !== []) {
            throw new RuntimeException("領域「{$name}」的 reviewers 至少要有兩位");
        }
        $clean[] = ['name' => $name, 'paths' => array_values($paths), 'reviewers' => array_values($reviewers)];
    }

    return ['domains' => $clean];
}

/**
 * 路徑比對：以 / 結尾代表整個目錄（前綴比對）；"*" 代表整個 repo；其餘用 fnmatch
 * （例如 app/Models/EventV2*.php）。fnmatch 加 FNM_PATHNAME，* 不跨目錄，與 CI 的 rules: changes 一致；
 * 兩邊語意不同時，會出現「名單認為要審、CI 卻沒建立核准 job」。要涵蓋子目錄就另列「目錄/」。
 */
function approvalPathMatches(string $pattern, string $path): bool
{
    if ($pattern === '*') {
        return true;
    }
    if (str_ends_with($pattern, '/')) {
        return str_starts_with($path, $pattern);
    }

    return fnmatch($pattern, $path, FNM_PATHNAME);
}

/**
 * 名單路徑換成 CI `rules: changes` 的寫法。CI 比對路徑時 * 不跨目錄，
 * 所以目錄（以 / 結尾）要在後面補上「** 與 /*」兩段，才涵蓋底下所有層的檔案；檔名與萬用字元檔名照原樣。
 * "*"（整個 repo）沒有對應寫法，名單有它就不該用 rules: changes 篩選，直接丟例外。
 */
function approvalCiChangesPattern(string $pattern): string
{
    if ($pattern === '*') {
        throw new RuntimeException('"*"（整個 repo）不能換成 rules: changes，這種名單不需要路徑比對');
    }

    return str_ends_with($pattern, '/') ? $pattern . '**/*' : $pattern;
}

/**
 * 比對名單與 CI 設定的路徑是否一致。兩邊各寫一份：名單給檢查程式判斷動到哪些領域，
 * CI 的 rules: changes 決定這張 MR 要不要出現「需要審核人核准」這個 job。
 * 兩邊不一致時，可能出現「該審的 MR 沒有 job」或「不用審的 MR 卻要等核准」。
 *
 * @param  list<string>  $ciPatterns  CI 設定裡的 changes 清單
 * @return array{missing_in_ci: list<string>, extra_in_ci: list<string>}
 */
function approvalCompareCiPaths(array $rules, array $ciPatterns): array
{
    $expected = [];
    foreach ($rules['domains'] as $domain) {
        foreach ($domain['paths'] as $pattern) {
            $expected[] = approvalCiChangesPattern($pattern);
        }
    }
    $expected = array_values(array_unique($expected));
    $actual = array_values(array_unique($ciPatterns));

    return [
        'missing_in_ci' => array_values(array_diff($expected, $actual)),
        'extra_in_ci' => array_values(array_diff($actual, $expected)),
    ];
}

/**
 * 從 CI 設定檔取出 changes 清單：只讀兩行標記之間的內容，每行只能是註解或「- "路徑"」（引號可省略）。
 * 不解析整份 YAML，因為 CI 映像檔沒有 yaml 擴充套件；所以格式從嚴，標記找不到、出現其他寫法
 * （例如行尾註解、跳脫字元）都丟例外，不讓看不懂的內容被默默略過。
 *
 * @return list<string>
 */
function approvalReadCiPaths(string $file, string $begin, string $end): array
{
    $raw = @file_get_contents($file);
    if ($raw === false) {
        throw new RuntimeException("讀不到 CI 設定：{$file}");
    }
    $lines = explode("\n", $raw);
    $from = null;
    $to = null;
    foreach ($lines as $i => $line) {
        if ($from === null && trim($line) === $begin) {
            $from = $i;
        } elseif ($from !== null && trim($line) === $end) {
            $to = $i;
            break;
        }
    }
    if ($from === null || $to === null) {
        throw new RuntimeException("CI 設定缺少路徑清單的標記（{$begin} / {$end}）：{$file}");
    }

    $paths = [];
    foreach (array_slice($lines, $from + 1, $to - $from - 1) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (preg_match('/^-\s+(?:"([^"\\\\#]+)"|([^"\'\s#\\\\]+))$/', $trimmed, $m) !== 1) {
            throw new RuntimeException("CI 設定的路徑清單有看不懂的寫法：{$trimmed}（每行只能是註解或 - \"路徑\"）");
        }
        $paths[] = $m[1] !== '' ? $m[1] : $m[2];
    }
    if ($paths === []) {
        throw new RuntimeException("CI 設定的路徑清單是空的：{$file}");
    }

    return $paths;
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
 * 找出這批變更檔案動到哪些領域。
 *
 * @param  list<string>  $changedPaths
 * @return list<array{name: string, paths: list<string>, reviewers: list<string>}>
 */
function approvalTouchedDomains(array $rules, array $changedPaths): array
{
    $touched = [];
    foreach ($rules['domains'] as $domain) {
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
 * PHP 檔前後兩版的程式邏輯是否相同，也就是只改了註解或排版。
 * 做法：用 PHP 內建的 PhpToken 把兩版各拆成語法片段，拿掉註解與空白後逐一比對。
 * 這是 PHP 執行前本來就會做的拆解，所以不會被換行、縮排騙過；改動一個數字、
 * 把程式註解掉、加上 declare(strict_types=1) 都會讓片段不同而照常要審。
 * 不含跳脫字元的字串，單引號與雙引號視為相同（Pint 會自動互換）；含跳脫字元的照原樣比，
 * 因為兩種引號對跳脫字元的解讀不同。任一版語法錯誤就當成有改動，從嚴要審。
 * 限制：少數套件會在執行時讀 docblock，這類 docblock 的改動會被當成沒改。
 */
function approvalPhpLogicUnchanged(string $old, string $new): bool
{
    $a = approvalPhpLogicTokens($old);

    return $a !== null && $a === approvalPhpLogicTokens($new);
}

/** @return list<string>|null 語法錯誤時回傳 null */
function approvalPhpLogicTokens(string $code): ?array
{
    try {
        $tokens = PhpToken:[REDACTED], TOKEN_PARSE);
    } catch (ParseError) {
        return null;
    }

    $out = [];
    foreach ($tokens as $token) {
        if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            continue;
        }
        $text = $token->text;
        if ($token->is([T_OPEN_TAG, T_CLOSE_TAG])) {
            // 開頭的 <?php 會連帶吃進後面的換行或空白，只比標籤本身
            $text = trim($text);
        } elseif ($token->is(T_CONSTANT_ENCAPSED_STRING) && !str_contains($text, '\\')) {
            $text = substr($text, 1, -1);
        }
        $out[] = $token->id . ' ' . $text;
    }

    return $out;
}

/**
 * 判定核准是否有效。
 *
 * @param  list<array<string, mixed>>  $notes  MR 留言（GitLab notes API 原樣）
 * @param  list<string>  $reviewers  動到的領域的審核人聯集
 * @param  string|null  $until  只看這個時間點以前的留言（合併後檢查傳 merged_at）
 * @return array{approved: bool, approvers: list<string>, since: string, reset_by_push: bool}
 *                                                                                            reset_by_push：MR 開單後有沒有再推過 commit（有的話，早於那次推送的核准都失效）
 */
function approvalEvaluate(array $notes, string $author, array $reviewers, string $mrCreatedAt, ?string $until = null): array
{
    // 作者空白時「排除作者」會失效，作者自己的核准會被當成別人的
    if ($author === '' || $mrCreatedAt === '') {
        throw new RuntimeException('MR 缺少作者或建立時間，無法判定核准');
    }
    $untilAt = $until !== null ? new DateTimeImmutable($until) : null;
    $createdAt = new DateTimeImmutable($mrCreatedAt);
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
    $sinceAt = gateLastPushAt($sorted, $mrCreatedAt, $until);

    // 每個人的最後一次動作；key 用小寫帳號。已排序，後面的直接覆蓋前面的
    $last = [];
    foreach ($sorted as $note) {
        $at = new DateTimeImmutable((string) ($note['created_at'] ?? ''));
        if ($untilAt !== null && $at > $untilAt) {
            continue;
        }
        $body = trim((string) ($note['body'] ?? ''));
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
    foreach ($last as $key => $action) {
        if (!$action['approved'] || $key === $authorLc || !in_array($key, $allowed, true)) {
            continue;
        }
        if ($action['at'] <= $sinceAt) {
            continue;
        }
        $approvers[] = $action['user'];
    }
    sort($approvers);

    return [
        'approved' => $approvers !== [],
        'approvers' => $approvers,
        'since' => $sinceAt->format('Y-m-d H:i:s') . ' UTC',
        'reset_by_push' => $sinceAt > $createdAt,
    ];
}

/**
 * 沒有有效核准時能不能緊急放行：MR 的來源分支（API 回傳的 source_branch）以 hotfix/ 開頭才行，
 * 判斷與其他合併關卡共用 gateIsHotfixBranch()。MR 的標籤不列入判斷。
 * 合併前（check）與合併後（audit）都從同一份 MR 資料取分支名，兩邊的判斷才一致。
 */
function approvalHotfixBypass(array $mr): bool
{
    return gateIsHotfixBranch((string) ($mr['source_branch'] ?? ''));
}

/**
 * 動到的領域的審核人聯集（保留名單順序、去重、不分大小寫）。
 *
 * @param  list<array{reviewers: list<string>}>  $domains
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
 * @param  list<array{name: string, reviewers: list<string>}>  $domains
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
        $text = $first !== null ? "{$first}（建議先找）" : '（名單上只剩作者本人，請更新名單）';
        if ($names !== []) {
            $text .= '、' . implode('、', $names);
        }
        $lines[] = "  ・{$domain['name']} → {$text}";
    }

    return $lines;
}
