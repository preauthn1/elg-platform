<?php

declare(strict_types=1);

/**
 * MR 合併關卡（PM 確認檢查、核准檢查等）共用的判定邏輯。只放不碰網路、不碰 git 的純函式，
 * 各道檢查的入口程式與測試都引用這一份，規則改了跑測試就知道有沒有改壞。
 * 碰網路的共用程式在 gitlab-api.php，碰 git 的在 git.php。
 *
 * 共用規則：
 * - 豁免：來源分支以 hotfix/ 開頭；或 commit 至少一顆、而且每一顆標題的類型都是
 *   docs／style／chore／test／revert（git revert 產生的 Revert "..." 也算 revert）。
 *   標題看不出類型的 commit 一顆就讓整批不豁免。
 * - 收集工單：只認 target/tickets#N、target/ticket_tasks#N、target/op_tickets#N 與這三個專案的工單網址；
 *   只寫 #N 不認，因為在 MR 裡它指的是這個 repo 自己的 issue。
 * - 團隊名單：GFF-Marketplace 的 team-roster.yaml，只認固定結構、格式從嚴，見 gateParseRoster()。
 *   比對成員一律用 GitLab 使用者 ID（帳號改名也不會變），不比對帳號名稱。
 * - 最後一次推 commit：取系統留言 "added N commit(s)" 的時間；MR 開單後沒再推過就用 MR 建立時間，
 *   見 gateLastPushAt()。
 */
const TICKETS_PROJECT = 'target/tickets';
const TICKET_TASKS_PROJECT = 'target/ticket_tasks';
const OP_TICKETS_PROJECT = 'target/op_tickets';
const ROSTER_PROJECT = 'target/gffv3/gff-marketplace';
const ROSTER_FILE = 'team-roster.yaml';
const ROSTER_REF = 'main';

/** 不影響功能、不必等合併關卡確認的 commit 類型。 */
const GATE_EXEMPT_COMMIT_TYPES = ['docs', 'style', 'chore', 'test', 'revert'];

/**
 * 來源分支是不是 hotfix 分支（以 hotfix/ 開頭）。各道合併關卡的 hotfix 判斷一律用這支。
 * 只認分支名：分支名在開分支時就定了，每個人、每支工具都看得到；MR 標籤、標題或口頭說法事後隨手就能改，不當依據。
 */
function gateIsHotfixBranch(string $sourceBranch): bool
{
    return str_starts_with($sourceBranch, 'hotfix/');
}

/**
 * 判斷這張 MR 能不能豁免，能的話回傳寫在報告裡的理由，不能回傳 null。
 *
 * @param  list<string>  $commitSubjects  要合進去的 commit 標題（已扣掉合併用的 commit）
 */
function gateExemptReason(string $sourceBranch, array $commitSubjects): ?string
{
    if (gateIsHotfixBranch($sourceBranch)) {
        return 'hotfix 分支';
    }
    if ($commitSubjects === []) {
        return null;
    }
    foreach ($commitSubjects as $subject) {
        if (!in_array(gateCommitType($subject), GATE_EXEMPT_COMMIT_TYPES, true)) {
            return null;
        }
    }

    return '全部 commit 都是 docs／style／chore／test／revert';
}

/**
 * 從 commit 標題取出類型（type: 或 type(scope): 開頭），看不出來時回傳 null。
 * git revert 自動產生的 Revert "..." 當成 revert。
 */
function gateCommitType(string $subject): ?string
{
    if (str_starts_with($subject, 'Revert "')) {
        return 'revert';
    }
    if (preg_match('/^([a-z]+)(?:\([^)]*\))?!?: \S/', $subject, $m) !== 1) {
        return null;
    }

    return $m[1];
}

/**
 * 從 MR 描述與 commit 訊息收集工單號，依專案分開、去重、由小到大排序。
 * 前面緊貼英數字、底線、斜線或減號時不認（例如 xxelg/tickets#1），與 commit 訊息檢查的寫法一致；
 * 網址的部分只看 target/<專案>/-/work_items/N 或 issues/N，不限主機名稱。
 *
 * @param  list<string>  $texts
 * @return array{tickets: list<int>, ticket_tasks: list<int>, op_tickets: list<int>}
 */
function gateCollectTickets(array $texts): array
{
    $found = ['tickets' => [], 'ticket_tasks' => [], 'op_tickets' => []];
    $short = '/(?<![A-Za-z0-9_\/-])target\/(tickets|ticket_tasks|op_tickets)#(\d+)/';
    $url = '/\/target\/(tickets|ticket_tasks|op_tickets)\/-\/(?:work_items|issues)\/(\d+)/';
    foreach ($texts as $text) {
        foreach ([$short, $url] as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $found[$m[1]][] = (int) $m[2];
            }
        }
    }
    foreach ($found as $project => $iids) {
        $iids = array_values(array_unique($iids));
        sort($iids);
        $found[$project] = $iids;
    }

    return $found;
}

/**
 * 解析 GFF-Marketplace 的 team-roster.yaml，取出每位成員的 user_id 與 groups。
 * CI 映像檔沒有 yaml 擴充套件，這裡只認這份檔案的固定結構：
 *   version: 1
 *   members:
 *     - username: Frank
 *       user_id: 7
 *       groups: [PM]
 * 每位成員都要有整數 user_id 與 [a, b] 寫法的 groups；其他欄位（call_names 等）略過不讀。
 * 格式從嚴：出現看不懂的行、缺欄位、版本不是 1，一律丟例外，不讓看不懂的名單被默默當成某組沒有人。
 *
 * @return list<array{username: string, user_id: int, groups: list<string>}>
 */
function gateParseRoster(string $yaml): array
{
    $lines = explode("\n", str_replace("\r\n", "\n", $yaml));
    if (!in_array('version: 1', array_map('rtrim', $lines), true)) {
        throw new RuntimeException('團隊名單格式看不懂：找不到 version: 1');
    }

    $members = [];
    $current = null;
    $inMembers = false;
    foreach ($lines as $no => $line) {
        $line = rtrim($line);
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }
        if (!str_starts_with($line, ' ')) {
            // 頂層的欄位：只有 members 底下要讀，其他頂層區塊略過
            $inMembers = $line === 'members:';

            continue;
        }
        if (!$inMembers) {
            continue;
        }

        if (preg_match('/^ {2}- ([a-z_]+):\s*(.*)$/', $line, $m) === 1) {
            if ($current !== null) {
                $members[] = $current;
            }
            $current = ['line' => $no + 1];
        } elseif (preg_match('/^ {4}([a-z_]+):\s*(.*)$/', $line, $m) !== 1 || $current === null) {
            throw new RuntimeException('團隊名單格式看不懂（第 ' . ($no + 1) . " 行）：{$line}");
        }
        $current[$m[1]] = $m[2];
    }
    if ($current !== null) {
        $members[] = $current;
    }
    if ($members === []) {
        throw new RuntimeException('團隊名單格式看不懂：members 底下沒有任何成員');
    }

    $clean = [];
    foreach ($members as $member) {
        $where = "第 {$member['line']} 行的成員";
        $userId = $member['user_id'] ?? '';
        if (preg_match('/^\d+$/', $userId) !== 1) {
            throw new RuntimeException("團隊名單格式看不懂：{$where}的 user_id 不是整數");
        }
        if (preg_match('/^\[([^\]#]*)\]$/', $member['groups'] ?? '', $g) !== 1) {
            throw new RuntimeException("團隊名單格式看不懂：{$where}的 groups 不是 [a, b] 寫法");
        }
        $groups = array_values(array_filter(array_map(
            static fn (string $s): string => trim($s, " \t\"'"),
            explode(',', $g[1]),
        ), static fn (string $s): bool => $s !== ''));
        $clean[] = ['username' => (string) ($member['username'] ?? ''), 'user_id' => (int) $userId, 'groups' => $groups];
    }

    return $clean;
}

/**
 * 名單裡某一組的使用者 ID。那一組是空的就丟例外：沒有人能放行時，整個檢查算不通過。
 *
 * @param  list<array{user_id: int, groups: list<string>}>  $roster
 * @return list<int>
 */
function gateRosterGroupUserIds(array $roster, string $group): array
{
    $ids = [];
    foreach ($roster as $member) {
        if (in_array($group, $member['groups'], true)) {
            $ids[] = $member['user_id'];
        }
    }
    if ($ids === []) {
        throw new RuntimeException("團隊名單的 {$group} 組是空的");
    }

    return $ids;
}

/**
 * MR 最後一次推 commit 的時間：系統留言 "added N commit(s)" 中最晚的一筆；開單後沒再推過就是 MR 建立時間。
 * 只看系統留言；傳了 $until 時只看那個時間點以前的留言（合併後檢查傳 merged_at）。
 * 不用 pipeline 建立時間，因為從 MR 頁手動重跑 pipeline 不代表有新的 commit。
 * 系統留言缺時間、或 MR 缺建立時間時丟例外，不猜。
 *
 * @param  list<array<string, mixed>>  $notes  MR 留言（GitLab notes API 原樣）
 */
function gateLastPushAt(array $notes, string $mrCreatedAt, ?string $until = null): DateTimeImmutable
{
    if ($mrCreatedAt === '') {
        throw new RuntimeException('MR 缺少建立時間，無法判斷最後一次推 commit 的時間');
    }
    $untilAt = $until !== null ? new DateTimeImmutable($until) : null;
    $lastPushAt = new DateTimeImmutable($mrCreatedAt);

    foreach ($notes as $note) {
        if (!is_array($note) || ($note['system'] ?? false) !== true) {
            continue;
        }
        if (!is_string($note['created_at'] ?? null) || $note['created_at'] === '') {
            throw new RuntimeException('系統留言缺少 created_at，無法判斷最後一次推 commit 的時間');
        }
        if (preg_match('/^added \d+ commits?\b/', trim((string) ($note['body'] ?? ''))) !== 1) {
            continue;
        }
        $at = new DateTimeImmutable($note['created_at']);
        if ($untilAt !== null && $at > $untilAt) {
            continue;
        }
        if ($at > $lastPushAt) {
            $lastPushAt = $at;
        }
    }

    return $lastPushAt;
}

/** GitLab 回傳的時間（UTC）換成台北時間，到分鐘。 */
function gateTaipeiTime(string $at): string
{
    return (new DateTimeImmutable($at))->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y-m-d H:i');
}
