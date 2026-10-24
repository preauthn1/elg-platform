<?php

declare(strict_types=1);

/**
 * QA 驗收檢查的判定邏輯。只放不碰網路、不碰 git 的純函式，供 qa-verify-check.php 與
 * qa-verify-check-test.php 共用，規則改了跑測試就知道有沒有改壞。
 * 與其他合併關卡共用的規則（豁免、收集工單、團隊名單格式、最後一次推 commit 的時間）在 .gitlab-ci/lib/merge-gate.php。
 *
 * 檢查在確認什麼：合進預設分支（master）的 MR，對應需求主單（target/tickets）底下的每張測試子單，
 * 都由測試組的人在 MR 最後一次推 commit 之後關閉。QA 測過的必須是最新的程式，所以推了新 commit 就要重新驗收。
 *
 * 定位：防疏忽、不防刻意繞過。CI 設定與本腳本都在同一個 repo，作者在自己的 MR 裡改掉它們，
 * 那張 MR 就會照改過的版本檢查；修改會留在 MR 的 diff 與 git 歷史。
 *
 * 判定規則：
 * - 豁免與收集工單：照 .gitlab-ci/lib/merge-gate.php 開頭的共用規則（gateExemptReason()、gateCollectTickets()）。
 *   只附線上問題單（沒有任何需求單）時，每張都查得到才豁免。
 * - 測試子單：主單底下標題以「- 測試」結尾的子單，也認「- APIs測試」「- Agent測試」「- 測試(批號)」這類加了前綴或後綴的寫法，
 *   見 qaVerifyIsTestSubtask()。一張主單確定沒有測試子單時，這張主單直接通過。
 * - 每張測試子單要同時符合：
 *   1. 目前是關閉狀態，而且狀態異動紀錄（resource_state_events）的最後一筆也是 closed，兩者不一致算不通過，不猜。
 *   2. 最後一筆關閉紀錄的操作者在團隊名單的「測試」組，一律比對 GitLab 使用者 ID（帳號改名也不會變）。
 *   3. 這筆關閉紀錄的時間嚴格晚於 MR 最後一次推 commit 的時間（gateLastPushAt()）。時間相同、缺漏或比不出先後，一律不通過。
 * - 有多張主單時，每張都要通過。查詢失敗（讀不到子單、讀不到狀態異動紀錄、讀不到最後一次推 commit 的時間）一律不通過。
 */

require_once __DIR__ . '/../../lib/merge-gate.php';

/** 團隊名單裡關閉測試子單的那一組。 */
const QA_VERIFY_GROUP = '測試';

/**
 * 名單裡測試組的使用者 ID。測試組是空的就丟例外：沒有人能驗收時，整個檢查算不通過。
 *
 * @param  list<array{user_id: int, groups: list<string>}>  $roster
 * @return list<int>
 */
function qaVerifyQaUserIds(array $roster): array
{
    return gateRosterGroupUserIds($roster, QA_VERIFY_GROUP);
}

/**
 * 子單標題是不是測試子單：標題結尾是「- 」接「測試」，中間可以夾英文前綴（APIs測試、Agent測試），後面可以接括號（測試(批號)）。
 * 只看結尾：開單工具把子任務後綴接在主單標題後面，標題中間的「- 測試環境」這類字樣不是後綴。
 * 開單工具的子任務後綴對照見 GFF-Marketplace team-roster.yaml 的 suffix_to_group。
 */
function qaVerifyIsTestSubtask(string $title): bool
{
    return preg_match('/-\s*[A-Za-z]*測試(?:\([^)]*\))?\s*$/u', $title) === 1;
}

/**
 * 判定一張主單底下的測試子單，回傳是否通過與報告的每一行。
 * 讀狀態異動紀錄與最後一次推 commit 時間由呼叫端傳入 callable：讀取失敗時丟例外，這裡把那張子單判為不通過，
 * 其他子單照常判定，報告看得出是哪一張讀不到。沒有測試子單時不會呼叫這兩個 callable。
 *
 * @param  list<array{reference: string, title: string, state: string}>  $children  主單底下的全部子單（已確認讀完）
 * @param  callable(string): list<array<string, mixed>>  $eventsOf  子單參照（例如 target/ticket_tasks#1825）→ 狀態異動紀錄原樣
 * @param  callable(): DateTimeImmutable  $lastPushAt  MR 最後一次推 commit 的時間
 * @param  list<int>  $qaUserIds
 * @return array{ok: bool, lines: list<string>}
 */
function qaVerifyJudgeMainTicket(int $mainIid, array $children, callable $eventsOf, callable $lastPushAt, array $qaUserIds): array
{
    $mainRef = TICKETS_PROJECT . "#{$mainIid}";
    $tests = array_values(array_filter($children, static fn (array $c): bool => qaVerifyIsTestSubtask($c['title'])));
    if ($tests === []) {
        return ['ok' => true, 'lines' => ["主單 {$mainRef}：沒有測試子單，不需要 QA 驗收"]];
    }

    $ok = true;
    $lines = [];
    foreach ($tests as $child) {
        $ref = "子單 {$child['reference']}「{$child['title']}」";
        try {
            $result = qaVerifyJudgeSubtask($child['state'], $eventsOf($child['reference']), $lastPushAt(), $qaUserIds);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'reason' => '查詢失敗（' . $e->getMessage() . '）'];
        }
        $lines[] = "{$ref}：{$result['reason']}";
        $ok = $ok && $result['ok'];
    }

    return ['ok' => $ok, 'lines' => $lines];
}

/**
 * 判定一張測試子單，回傳是否通過與寫在報告裡的理由。
 *
 * @param  string  $state  子單目前的狀態（GraphQL 的 OPEN／CLOSED 或 REST 的 opened／closed，不分大小寫）
 * @param  list<array<string, mixed>>  $events  GitLab resource_state_events API 原樣：state、user.id、user.username、created_at（UTC）
 * @param  list<int>  $qaUserIds
 * @return array{ok: bool, reason: string}
 */
function qaVerifyJudgeSubtask(string $state, array $events, DateTimeImmutable $lastPushAt, array $qaUserIds): array
{
    if (strtolower($state) !== 'closed') {
        return ['ok' => false, 'reason' => '還沒關閉'];
    }

    $last = qaVerifyLastStateEvent($events);
    if ($last === null) {
        return ['ok' => false, 'reason' => '目前是關閉，但找不到狀態異動紀錄，無法確認誰在什麼時候關閉'];
    }
    if ($last['state'] !== 'closed') {
        return ['ok' => false, 'reason' => "目前是關閉，但狀態異動紀錄最後一筆是 {$last['state']}，兩者對不上"];
    }

    $user = $last['username'];
    $closedAt = gateTaipeiTime($last['at']);
    if (!in_array($last['user_id'], $qaUserIds, true)) {
        return ['ok' => false, 'reason' => "由 {$user} 於 {$closedAt} 台北時間關閉，不在測試名單"];
    }
    if (new DateTimeImmutable($last['at']) <= $lastPushAt) {
        $pushedAt = gateTaipeiTime($lastPushAt->format(DATE_RFC3339_EXTENDED));

        return ['ok' => false, 'reason' => "{$user} 於 {$closedAt} 台北時間關閉，沒有晚於 MR 最後一次推 commit（{$pushedAt} 台北時間），要重新驗收"];
    }

    return ['ok' => true, 'reason' => "通過（{$user} 於 {$closedAt} 台北時間關閉）"];
}

/**
 * 狀態異動紀錄中時間最晚的一筆。沒有任何紀錄回傳 null。
 * 缺狀態、缺時間、缺操作者的使用者 ID，或最晚的時間有兩筆以上（比不出先後）時丟例外，不猜。
 *
 * @param  list<array<string, mixed>>  $events
 * @return ?array{state: string, user_id: int, username: string, at: string}
 */
function qaVerifyLastStateEvent(array $events): ?array
{
    $last = null;
    $lastAt = null;
    $tie = false;
    foreach ($events as $event) {
        if (!is_array($event)) {
            throw new RuntimeException('狀態異動紀錄格式看不懂');
        }
        $at = $event['created_at'] ?? null;
        if (!is_string($at) || $at === '') {
            throw new RuntimeException('狀態異動紀錄缺少 created_at，無法判斷先後');
        }
        $time = new DateTimeImmutable($at);
        if ($lastAt === null || $time > $lastAt) {
            [$last, $lastAt, $tie] = [$event, $time, false];
        } elseif ($time == $lastAt) {
            $tie = true;
        }
    }
    if ($last === null) {
        return null;
    }
    if ($tie) {
        throw new RuntimeException('狀態異動紀錄最後兩筆時間相同，判斷不出先後');
    }

    $state = $last['state'] ?? null;
    if (!is_string($state) || $state === '') {
        throw new RuntimeException('狀態異動紀錄缺少 state');
    }
    $userId = $last['user']['id'] ?? null;
    if (!is_int($userId)) {
        throw new RuntimeException('狀態異動紀錄缺少操作者的使用者 ID');
    }

    return [
        'state' => $state,
        'user_id' => $userId,
        'username' => (string) ($last['user']['username'] ?? "使用者 {$userId}"),
        'at' => (string) $last['created_at'],
    ];
}

/**
 * 組出固定格式的報告。
 *
 * @param  string  $verdict  通過、不通過，或「豁免（理由）」
 * @param  list<string>  $lines  每張子單（或沒有測試子單的主單）一行
 */
function qaVerifyReport(string $verdict, array $lines, ?string $rosterCommit, ?string $lastPushAt = null): string
{
    $out = "QA 驗收檢查：{$verdict}\n";
    foreach ($lines as $line) {
        $out .= "- {$line}\n";
    }
    if ($lastPushAt !== null) {
        $out .= "MR 最後一次推 commit：{$lastPushAt} 台北時間\n";
    }
    if ($rosterCommit !== null && $rosterCommit !== '') {
        $out .= '名單版本：team-roster.yaml @ ' . substr($rosterCommit, 0, 8) . "\n";
    }

    return $out;
}
