<?php

declare(strict_types=1);

/**
 * PM 確認檢查的判定邏輯。只放不碰網路、不碰 git 的純函式，供 pm-confirm-check.php 與
 * pm-confirm-check-test.php 共用，規則改了跑測試就知道有沒有改壞。
 * 與其他合併關卡共用的規則（豁免、收集工單、團隊名單格式）在 .gitlab-ci/lib/merge-gate.php。
 *
 * 檢查在確認什麼：合進預設分支（master）的 MR，對應的需求主單（target/tickets）掛著「0. PM確認」標籤，
 * 而且最後一次加上這個標籤的人在團隊名單的 PM 組。規則以 dev-toolkit 的 release-workflow
 * 參考文件「PM 確認檢查」（reference/pm-confirm-check.md）為準，這裡是同一套規則的 CI 版本。
 * MR pipeline 看主單目前的標籤（pmConfirmJudgeMainTicket()）；合併後檢查看「合併當下」的標籤
 * （pmConfirmJudgeAtMerge()），由合併時間以前的標籤異動紀錄重建，合併後才補貼或才移除都不影響結果。
 *
 * 定位：防疏忽、不防刻意繞過。CI 設定與本腳本都在同一個 repo，作者在自己的 MR 裡改掉它們，
 * 那張 MR 就會照改過的版本檢查；修改會留在 MR 的 diff 與 git 歷史。
 *
 * 判定規則：
 * - 豁免與收集工單：照 .gitlab-ci/lib/merge-gate.php 開頭的共用規則（gateExemptReason()、gateCollectTickets()）。
 * - 只附線上問題單（沒有任何需求單）時，每張都查得到才豁免。
 * - 最後一次加標籤的人：從標籤異動紀錄（resource_label_events）取這個標籤 action=add 中時間最晚的一筆。
 *   同一秒內這個標籤有兩筆以上異動時，先後判斷不出來，算不通過，不猜。
 * - 合併當下有沒有標籤：只看合併時間以前的異動，這個標籤最後一筆異動是 add 才算掛著。
 *   異動與合併在同一秒時，判斷不出是合併前還是合併後，也算不通過。
 * - 是不是 PM 一律比對 GitLab 使用者 ID（帳號改名也不會變），不比對帳號名稱。
 */

require_once __DIR__ . '/../../lib/merge-gate.php';

const PM_CONFIRM_LABEL = '0. PM確認';

/**
 * 名單裡 PM 組的使用者 ID。PM 組是空的就丟例外：沒有人能放行時，整個檢查算不通過。
 *
 * @param  list<array{user_id: int, groups: list<string>}>  $roster
 * @return list<int>
 */
function pmConfirmPmUserIds(array $roster): array
{
    return gateRosterGroupUserIds($roster, 'PM');
}

/**
 * 從標籤異動紀錄找出最後一次加上指定標籤的人。
 * status：added（找到，而且之後沒有這個標籤的其他異動）、ambiguous（同一秒有兩筆以上這個標籤的異動，
 * 或有異動和截止時間在同一秒，先後判斷不出來）、missing（沒有任何加上這個標籤的紀錄）、
 * mismatch（最後一次加上之後又有移除）。
 * 傳了 $until 時只看那個時間點以前的異動（合併後檢查傳 merged_at），不傳就看全部。
 * 異動紀錄的 label 可能是 null（標籤已被刪除），這類紀錄略過。缺時間或缺使用者時丟例外，不猜。
 *
 * @param  list<array<string, mixed>>  $events  GitLab resource_label_events API 原樣
 * @return array{status: string, user_id?: int, username?: string, at?: string}
 */
function pmConfirmLastAdder(array $events, string $label, ?string $until = null): array
{
    $untilSec = $until !== null ? (int) (new DateTimeImmutable($until))->format('U') : null;
    $relevant = [];
    foreach ($events as $event) {
        if (!is_array($event) || ($event['label']['name'] ?? null) !== $label) {
            continue;
        }
        $at = $event['created_at'] ?? null;
        if (!is_string($at) || $at === '') {
            throw new RuntimeException('標籤異動紀錄缺少 created_at，無法判斷先後');
        }
        $sec = (int) (new DateTimeImmutable($at))->format('U');
        if ($untilSec !== null && $sec > $untilSec) {
            continue;
        }
        if ($sec === $untilSec) {
            return ['status' => 'ambiguous'];
        }
        $relevant[] = $event + ['_sec' => $sec];
    }

    $last = null;
    foreach ($relevant as $event) {
        if (($event['action'] ?? '') === 'add' && ($last === null || $event['_sec'] > $last['_sec'])) {
            $last = $event;
        }
    }
    if ($last === null) {
        return ['status' => 'missing'];
    }

    $sameSecond = 0;
    foreach ($relevant as $event) {
        if ($event['_sec'] === $last['_sec']) {
            $sameSecond++;
        }
        if ($event['_sec'] > $last['_sec']) {
            return ['status' => 'mismatch'];
        }
    }
    if ($sameSecond > 1) {
        return ['status' => 'ambiguous'];
    }

    $userId = $last['user']['id'] ?? null;
    if (!is_int($userId)) {
        throw new RuntimeException('標籤異動紀錄缺少操作者的使用者 ID');
    }

    return [
        'status' => 'added',
        'user_id' => $userId,
        'username' => (string) ($last['user']['username'] ?? "使用者 {$userId}"),
        'at' => (string) $last['created_at'],
    ];
}

/**
 * 判定一張主單目前的狀態（MR pipeline 用），回傳是否通過與報告裡的那一行。
 *
 * @param  list<string|array{name?: string}>  $labels  主單目前的標籤（issues API 的 labels）
 * @param  list<array<string, mixed>>  $events  主單的標籤異動紀錄
 * @param  list<int>  $pmUserIds
 * @return array{ok: bool, line: string}
 */
function pmConfirmJudgeMainTicket(int $iid, array $labels, array $events, array $pmUserIds): array
{
    $ref = "主單 target/tickets#{$iid}";
    $names = array_map(static fn ($l): string => (string) (is_array($l) ? ($l['name'] ?? '') : $l), $labels);
    if (!in_array(PM_CONFIRM_LABEL, $names, true)) {
        return ['ok' => false, 'line' => "{$ref}：沒有 " . PM_CONFIRM_LABEL];
    }

    return pmConfirmJudgeAdder($ref, pmConfirmLastAdder($events, PM_CONFIRM_LABEL), $pmUserIds, [
        'missing' => '找不到加上 ' . PM_CONFIRM_LABEL . ' 的異動紀錄',
        'mismatch' => '標籤異動紀錄與目前的標籤對不上（最後一次加上之後又被移除）',
    ]);
}

/**
 * 判定一張主單在合併當下的狀態（合併後檢查用），回傳是否通過與報告裡的那一行。
 * 不看主單目前的標籤：合併時間以前，這個標籤最後一筆異動是 add 才算掛著，見 pmConfirmLastAdder()。
 *
 * @param  list<array<string, mixed>>  $events  主單的標籤異動紀錄
 * @param  list<int>  $pmUserIds
 * @param  string  $mergedAt  MR 的合併時間（GitLab merged_at 原樣）
 * @return array{ok: bool, line: string}
 */
function pmConfirmJudgeAtMerge(int $iid, array $events, array $pmUserIds, string $mergedAt): array
{
    return pmConfirmJudgeAdder("主單 target/tickets#{$iid}", pmConfirmLastAdder($events, PM_CONFIRM_LABEL, $mergedAt), $pmUserIds, [
        'missing' => '合併當下沒有 ' . PM_CONFIRM_LABEL . '（合併前沒有加上的異動紀錄）',
        'mismatch' => '合併當下沒有 ' . PM_CONFIRM_LABEL . '（合併前最後一次加上之後又被移除）',
    ]);
}

/**
 * 依最後一次加標籤的結果組出判定。找到加的人時，比對是不是 PM。
 *
 * @param  array{status: string, user_id?: int, username?: string, at?: string}  $adder  pmConfirmLastAdder() 的結果
 * @param  list<int>  $pmUserIds
 * @param  array{missing: string, mismatch: string}  $reasons  missing、mismatch 時報告寫的原因
 * @return array{ok: bool, line: string}
 */
function pmConfirmJudgeAdder(string $ref, array $adder, array $pmUserIds, array $reasons): array
{
    return match ($adder['status']) {
        'ambiguous' => ['ok' => false, 'line' => "{$ref}：標籤異動先後判斷不出來"],
        'missing' => ['ok' => false, 'line' => "{$ref}：{$reasons['missing']}"],
        'mismatch' => ['ok' => false, 'line' => "{$ref}：{$reasons['mismatch']}"],
        default => in_array($adder['user_id'], $pmUserIds, true)
            ? ['ok' => true, 'line' => "{$ref}：通過（{$adder['username']} 於 " . gateTaipeiTime($adder['at']) . ' 台北時間加上）']
            : ['ok' => false, 'line' => "{$ref}：" . PM_CONFIRM_LABEL . " 是 {$adder['username']} 加的，不在 PM 名單"],
    };
}

/**
 * 組出固定格式的報告。
 *
 * @param  string  $verdict  通過、不通過，或「豁免（理由）」
 * @param  list<string>  $lines  每張工單一行
 */
function pmConfirmReport(string $verdict, array $lines, ?string $rosterCommit): string
{
    $out = "PM 確認檢查：{$verdict}\n";
    foreach ($lines as $line) {
        $out .= "- {$line}\n";
    }
    if ($rosterCommit !== null && $rosterCommit !== '') {
        $out .= '名單版本：team-roster.yaml @ ' . substr($rosterCommit, 0, 8) . "\n";
    }

    return $out;
}
