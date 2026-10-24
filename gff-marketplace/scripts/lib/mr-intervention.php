<?php

declare(strict_types=1);

/**
 * MR 介入通知的判定邏輯。只放不碰網路的純函式，供 mr-intervention-notify.php 與
 * mr-intervention-test.php 共用。
 *
 * 排程 pipeline 每次掃描開著的 MR，找出「輪到某人動手」的事件，輪到誰就 @ 誰。目前有三種：
 * - 指派審核人（reviewer）：新加進來的審核人才通知，Draft 也發，作者把自己設成審核人不通知。
 * - 核准因新 commit 失效（stale）：@ 核准已失效、而且這張 MR 還缺有效核准的那幾位審核人。
 * - 可以合併（mergeable）：@ MR 作者。條件全部成立才算：
 *   不是 Draft；GitLab 判定可以合併（detailed_merge_status 是 mergeable）；
 *   動到核准領域時，有該領域審核人的有效核准；有指派審核人時，其中任一位有有效核准
 *   （與「需要審核人核准」一致，任一位就算，不要求每位都核准）。
 *   核准失效後還沒重新核准，就不算有效核准，所以也不會通知。
 * GFF 沒有 CI 端的 PM 確認檢查，所以沒有「主單缺 0. PM確認」這個事件。
 *
 * 去重：機器人在每張 MR 維護一則自己的紀錄留言，尾端的隱藏標記存已通知過的事。
 * 審核人記帳號；核准失效與可以合併記當時 MR 的最新 commit，同一件事在新的 commit 上再發生仍會通知。
 * 只認機器人自己寫的紀錄留言，別人貼上同樣的標記不算。
 */

require_once __DIR__ . '/approval.php';

const MR_INTERVENTION_MARKER = 'mr-intervention-state:';

/**
 * 紀錄留言的初始狀態：還沒通知過任何事。
 *
 * @return array{reviewers: list<string>, stale_sha: string, mergeable_sha: string}
 */
function mrInterventionEmptyState(): array
{
    return ['reviewers' => [], 'stale_sha' => '', 'mergeable_sha' => ''];
}

/**
 * 從 MR 留言找出機器人自己的紀錄留言。找不到回傳 null；標記裡的 JSON 壞掉時丟例外，
 * 不當成「沒通知過」，否則會把已通知過的事全部重發一次。
 *
 * @param list<array<string, mixed>> $notes
 * @return array{note_id: int, state: array{reviewers: list<string>, stale_sha: string, mergeable_sha: string}}|null
 */
function mrInterventionFindState(array $notes, string $botUser): ?array
{
    foreach ($notes as $note) {
        if (!is_array($note) || ($note['system'] ?? false) === true) {
            continue;
        }
        if (strtolower((string) ($note['author']['username'] ?? '')) !== strtolower($botUser)) {
            continue;
        }
        if (preg_match('/<!-- ' . preg_quote(MR_INTERVENTION_MARKER, '/') . ' (.*?) -->/s', (string) ($note['body'] ?? ''), $m) !== 1) {
            continue;
        }
        $data = json_decode($m[1], true);
        if (!is_array($data)) {
            throw new RuntimeException('MR 介入通知的紀錄留言格式不對（留言 ID ' . (int) ($note['id'] ?? 0) . '）');
        }

        return [
            'note_id' => (int) ($note['id'] ?? 0),
            'state'   => [
                'reviewers'     => array_values(array_filter((array) ($data['reviewers'] ?? []), 'is_string')),
                'stale_sha'     => (string) ($data['stale_sha'] ?? ''),
                'mergeable_sha' => (string) ($data['mergeable_sha'] ?? ''),
            ],
        ];
    }

    return null;
}

/**
 * 紀錄留言的內文。看得到的部分只說明這則留言的用途，不 @ 任何人：
 * 每次更新都是編輯這則留言，內文有 @ 的話，GitLab 可能每次都再通知一次。
 */
function mrInterventionStateBody(array $state): string
{
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return "MR 介入通知的紀錄：記下這張 MR 已經通知過哪些事，避免重複通知。由機器人自動更新，請不要編輯或刪除。\n"
        . "\n"
        . '<!-- ' . MR_INTERVENTION_MARKER . " {$json} -->";
}

/**
 * 算出這張 MR 的核准狀況，供判定事件用。
 *
 * @param array<string, mixed>       $mr              GitLab 單張 MR API 原樣
 * @param list<array<string, mixed>> $notes           MR 留言
 * @param list<string>               $domainReviewers 動到的核准領域的審核人聯集；沒動到任何領域時給空陣列
 * @return array{approval_pending: bool, stale: list<string>}
 *         approval_pending：還在等審核人核准（領域核准或指派審核人的核准，任一項還缺）
 *         stale：核准已失效、而且那一項還缺核准的審核人
 */
function mrInterventionApproval(array $mr, array $notes, array $domainReviewers): array
{
    $author    = (string) ($mr['author']['username'] ?? '');
    $createdAt = (string) ($mr['created_at'] ?? '');
    $assigned  = mrInterventionAssignedReviewers($mr);

    $pending = false;
    $stale   = [];
    foreach ([$domainReviewers, $assigned] as $reviewers) {
        if ($reviewers === []) {
            continue;
        }
        $result = approvalEvaluate($notes, $author, $reviewers, $createdAt);
        if (!$result['approved']) {
            $pending = true;
            array_push($stale, ...$result['stale']);
        }
    }

    return ['approval_pending' => $pending, 'stale' => mrInterventionUniqueUsers($stale)];
}

/**
 * MR 指派的審核人帳號，排除作者本人。
 *
 * @return list<string>
 */
function mrInterventionAssignedReviewers(array $mr): array
{
    $author = strtolower((string) ($mr['author']['username'] ?? ''));
    $users  = [];
    foreach ((array) ($mr['reviewers'] ?? []) as $reviewer) {
        $name = is_array($reviewer) ? (string) ($reviewer['username'] ?? '') : '';
        if ($name !== '' && strtolower($name) !== $author) {
            $users[] = $name;
        }
    }

    return mrInterventionUniqueUsers($users);
}

/**
 * 判定這次掃描要通知哪些事，並回傳通知完之後的紀錄。
 * $baseline 為 true 時只建立紀錄、不通知（上線前就開著的 MR 第一次被掃到時）。
 *
 * @param array<string, mixed>                       $mr
 * @param array{approval_pending: bool, stale: list<string>} $approval
 * @param array{reviewers: list<string>, stale_sha: string, mergeable_sha: string} $state
 * @return array{events: list<array{type: string, to: list<string>}>, state: array{reviewers: list<string>, stale_sha: string, mergeable_sha: string}}
 */
function mrInterventionDetect(array $mr, array $approval, array $state, bool $baseline = false): array
{
    $sha    = (string) ($mr['sha'] ?? '');
    $events = [];
    $next   = $state;

    $notified = array_map('strtolower', $state['reviewers']);
    $assigned = mrInterventionAssignedReviewers($mr);
    $newOnes  = array_values(array_filter($assigned, static fn (string $u): bool => !in_array(strtolower($u), $notified, true)));
    if ($newOnes !== []) {
        $events[]          = ['type' => 'reviewer', 'to' => $newOnes];
        $next['reviewers'] = mrInterventionUniqueUsers([...$state['reviewers'], ...array_map('strtolower', $newOnes)]);
    }

    if ($approval['stale'] !== [] && $sha !== '' && $state['stale_sha'] !== $sha) {
        $events[]          = ['type' => 'stale', 'to' => $approval['stale']];
        $next['stale_sha'] = $sha;
    }

    $ready = ($mr['draft'] ?? false) !== true
        && ($mr['detailed_merge_status'] ?? '') === 'mergeable'
        && !$approval['approval_pending'];
    $author = (string) ($mr['author']['username'] ?? '');
    if ($ready && $author !== '' && $sha !== '' && $state['mergeable_sha'] !== $sha) {
        $events[]              = ['type' => 'mergeable', 'to' => [$author]];
        $next['mergeable_sha'] = $sha;
    }

    return ['events' => $baseline ? [] : $events, 'state' => $next];
}

/**
 * 只把已成功通知的事件寫進紀錄：從判定後的紀錄取出這個事件對應的欄位。
 *
 * @param array{reviewers: list<string>, stale_sha: string, mergeable_sha: string} $current
 * @param array{reviewers: list<string>, stale_sha: string, mergeable_sha: string} $detected
 * @return array{reviewers: list<string>, stale_sha: string, mergeable_sha: string}
 */
function mrInterventionMergeState(array $current, array $detected, string $type): array
{
    $field = match ($type) {
        'reviewer'  => 'reviewers',
        'stale'     => 'stale_sha',
        'mergeable' => 'mergeable_sha',
    };
    $current[$field] = $detected[$field];

    return $current;
}

/**
 * 解析 GitLab 帳號對 Slack user id 的對照表（JSON 物件）。key 轉小寫，比對帳號不分大小寫。
 * 空字串代表沒設定，回傳空陣列；格式不對丟例外。
 *
 * @return array<string, string>
 */
function mrInterventionParseUserMap(string $json): array
{
    if (trim($json) === '') {
        return [];
    }
    $data = json_decode($json, true);
    if (!is_array($data) || array_is_list($data) && $data !== []) {
        throw new RuntimeException('SLACK_USER_MAP 不是 JSON 物件（格式：{"GitLab 帳號": "Slack user id"}）');
    }
    $map = [];
    foreach ($data as $user => $slackId) {
        if (is_string($slackId) && $slackId !== '') {
            $map[strtolower((string) $user)] = $slackId;
        }
    }

    return $map;
}

/**
 * 重審後要做的事。動到核准領域時才有「需要審核人核准」要按；沒動到時那個 job 直接通過，不叫人去按。
 */
function reapproveSteps(bool $domainTouched): string
{
    return $domainTouched
        ? '重審後請先按「撤銷核准」、再按「核准」，然後到 pipeline 按「需要審核人核准」的 ▶'
        : '重審後請先按「撤銷核准」、再按「核准」';
}

/**
 * 核准失效留言裡已經提醒到的帳號（小寫）。只認兩種寫法：
 * 「提醒重新核准」的「@帳號 你在 … 的核准」，與 staleNoteBody 開頭的「@帳號 @帳號 你的核准」。
 * 留言裡其他的 @（例如推新 commit 的人）不算提醒到。
 *
 * @return list<string>
 */
function staleMentionedUsers(string $body): array
{
    $users = [];
    if (preg_match_all('/@([\w.\-]+) 你在 /u', $body, $m) > 0) {
        $users = $m[1];
    }
    if (preg_match_all('/^((?:@[\w.\-]+ )+)你的核准/mu', $body, $m) > 0) {
        foreach ($m[1] as $run) {
            preg_match_all('/@([\w.\-]+)/u', $run, $names);
            $users = [...$users, ...$names[1]];
        }
    }

    return array_values(array_unique(array_map('strtolower', $users)));
}

/**
 * 核准失效的留言。這次推送已經有帶標記的留言時，只 @ 那則留言沒提醒到的人，而且不再帶標記；
 * 「提醒重新核准」只 @ 領域名單裡的審核人，名單外的指派審核人要由這裡補。都提醒到了就不留言。
 *
 * @param list<string>               $users
 * @param list<array<string, mixed>> $notes
 */
function staleNoteBody(array $users, array $notes, string $sha, bool $domainTouched): ?string
{
    $marked = staleNoticeExists($notes, $sha);
    if ($marked) {
        $mentioned = [];
        foreach ($notes as $note) {
            $body = (string) ($note['body'] ?? '');
            if (($note['system'] ?? false) !== true && str_contains($body, staleMarker($sha))) {
                $mentioned = [...$mentioned, ...staleMentionedUsers($body)];
            }
        }
        $users = array_values(array_filter($users, static fn (string $u): bool => !in_array(strtolower($u), $mentioned, true)));
        if ($users === []) {
            return null;
        }
    }
    $at = implode(' ', array_map(static fn (string $u): string => "@{$u}", $users));

    return "{$at} 你的核准已因新 commit（" . substr($sha, 0, 7) . '）失效。' . reapproveSteps($domainTouched) . '。'
        . ($marked ? '' : "\n\n" . staleMarker($sha));
}

/** Slack 訊息裡的稱呼：對照表有就用 <@id> 提及，對不到就只寫 GitLab 帳號、不 @。 */
function mrInterventionSlackName(string $user, array $map): string
{
    $id = $map[strtolower($user)] ?? null;

    return $id !== null ? "<@{$id}>" : $user;
}

/**
 * 帳號去重（不分大小寫，保留第一次出現的寫法）。
 *
 * @param list<string> $users
 * @return list<string>
 */
function mrInterventionUniqueUsers(array $users): array
{
    $seen = [];
    foreach ($users as $user) {
        $seen[strtolower($user)] ??= $user;
    }

    return array_values($seen);
}
