<?php

declare(strict_types=1);

/**
 * MR 介入通知：排程 pipeline 的 job「MR 介入通知」。掃描所有開著的 MR，輪到誰動手就在 MR 留言加 Slack @ 誰。
 * 事件種類、可以合併的條件與去重規則見 scripts/lib/mr-intervention.php 開頭。
 *
 * 為什麼用排程掃描：加審核人、按核准、貼標籤都不會觸發 pipeline，只靠 MR pipeline 會漏發。代價是最多延遲一個排程間隔。
 *
 * 通知管道：
 * - 指派審核人、可以合併：MR 留言（GitLab 的 @ 通知）加 Slack。
 * - 核准失效：MR 留言加 Slack。MR 留言與 MR pipeline 的「提醒重新核准」（scripts/approval-check.php notify-stale）
 *   共用同一個隱藏標記。同一次推送這裡先留言，那個 job 就不再留；那個 job 先留言，這裡只補 @ 它沒提醒到的人。
 *   那個 job 只管動到核准領域的 MR，其餘由這裡留言。
 * MR 留言送出後才記進紀錄留言；留言送不出去時不記，下次掃描再試。Slack 送不出去時照樣記，
 * MR 上已經有留言，不重發，job 以失敗結束讓 pipeline 顯示警告。
 *
 * 上線基準：專案 CI 變數 MR_NOTIFY_SINCE（ISO 8601 時間，例如 2026-10-22T09:00:00+08:00）。
 * - 沒設：每張 MR 都只建立或更新紀錄、不發任何通知。
 * - 有設：在這個時間之前建立、而且還沒有紀錄留言的 MR，第一次掃描只建立紀錄；其餘照常通知。
 *   上線當下所有開著的 MR 不會一次全部發。
 *
 * 用到的 CI 變數：
 *   CI_API_V4_URL、CI_PROJECT_ID
 *   APPROVAL_BOT_TOKEN  讀 MR、寫留言（Project Access Token，api scope）。沒設就略過整個 job
 *   SLACK_BOT_TOKEN、SLACK_CHANNEL_ID  Slack（Protected；排程跑在 main，讀得到）。沒設就只印在 job log
 *   SLACK_USER_MAP      target/gffv3 群組變數，GitLab 帳號對 Slack user id 的 JSON 物件。對不到的人只寫帳號、不 @
 *   MR_NOTIFY_SINCE     上線基準，見上
 *   MR_NOTIFY_DRY_RUN=1 本機試跑：只印要做的事，不寫 MR 留言、不發 Slack
 * 不看 APPROVAL_ENFORCE：試行模式下也要有人知道輪到自己。
 */

require __DIR__ . '/lib/mr-intervention.php';
require __DIR__ . '/lib/git-changes.php';

const RULES_FILE = __DIR__ . '/../.gitlab/approval-rules.json';

/**
 * 以 APPROVAL_BOT_TOKEN 呼叫 GitLab API。GET 時 $paginate 為 true 會自動翻頁。HTTP 錯誤直接丟例外。
 *
 * @return array<mixed>
 */
function gitlabApi(string $method, string $path, array $query = [], ?array $body = null, bool $paginate = false): array
{
    $base  = rtrim((string) getenv('CI_API_V4_URL'), '/');
    $token = [REDACTED] getenv('APPROVAL_BOT_TOKEN');
    $all   = [];
    $page  = 1;

    do {
        $q   = $paginate ? $query + ['per_page' => 100, 'page' => $page] : $query;
        $url = "{$base}{$path}" . ($q !== [] ? '?' . http_build_query($q) : '');
        $ch  = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => ["PRIVATE-TOKEN: [REDACTED]", 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ];
        if ($body !== null) {
            $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opt);
        $resp = (string) curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($err !== '' || $code >= 400) {
            throw new RuntimeException("GitLab API 失敗（{$method} {$path}，HTTP {$code}）：" . ($err !== '' ? $err : mb_substr($resp, 0, 300)));
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            throw new RuntimeException("GitLab API 回傳不是 JSON（{$method} {$path}）");
        }
        if (!$paginate) {
            return $data;
        }
        array_push($all, ...$data);
        $page++;
    } while (count($data) === 100);

    return $all;
}

function isDryRun(): bool
{
    return getenv('MR_NOTIFY_DRY_RUN') === '1';
}

/**
 * 發 Slack。沒設定憑證或試跑時只印出來，回傳 true；送出失敗回傳 false。
 */
function sendSlack(string $text): bool
{
    $token   = [REDACTED] getenv('SLACK_BOT_TOKEN');
    $channel = (string) getenv('SLACK_CHANNEL_ID');
    if (isDryRun() || $token === '' || $channel === '') {
        echo "  --- 未送 Slack（試跑或未設定 SLACK_BOT_TOKEN / SLACK_CHANNEL_ID）---\n  {$text}\n";

        return true;
    }

    $ch = curl_init('https://example.invalid/api/chat.postMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['channel' => $channel, 'text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}", 'Content-Type: application/json; charset=utf-8'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = (string) curl_exec($ch);
    // Slack 即使 HTTP 200 也可能回 {"ok":false}，要看 ok 欄位
    if ((json_decode($resp, true)['ok'] ?? false) !== true) {
        echo "  ✘ Slack 送出失敗：" . (curl_error($ch) ?: $resp) . "\n";

        return false;
    }

    return true;
}

/**
 * 事件的 MR 留言內文；回傳 null 表示這個事件不留言。
 * 核准失效的留言與「提醒重新核准」共用隱藏標記，這次推送已經有人留過時只補 @ 沒提醒到的人，規則見 staleNoteBody。
 *
 * @param array{type: string, to: list<string>} $event
 * @param array<string, mixed>                  $mr
 * @param list<array<string, mixed>>            $notes
 */
function eventNoteBody(array $event, array $mr, array $notes, bool $domainTouched, bool $approvalNeeded): ?string
{
    $at  = implode(' ', array_map(static fn (string $u): string => "@{$u}", $event['to']));
    $sha = (string) ($mr['sha'] ?? '');

    return match ($event['type']) {
        'reviewer'  => "{$at} 你被指派為這張 MR 的審核人，請審核。",
        'stale'     => staleNoteBody($event['to'], $notes, $sha, $domainTouched),
        'mergeable' => "{$at} 這張 MR 已符合合併條件（不是 Draft、"
            . ($approvalNeeded ? '審核人已核准' : '不需要審核人核准')
            . '、GitLab 判定可以合併），可以按合併了。',
        default     => null,
    };
}

/**
 * 事件的 Slack 訊息。
 *
 * @param array{type: string, to: list<string>} $event
 * @param array<string, mixed>                  $mr
 * @param array<string, string>                 $userMap
 */
function eventSlackText(array $event, array $mr, array $userMap, bool $domainTouched): string
{
    $who   = implode(' ', array_map(static fn (string $u): string => mrInterventionSlackName($u, $userMap), $event['to']));
    $iid   = (int) ($mr['iid'] ?? 0);
    $title = (string) ($mr['title'] ?? '');
    $url   = (string) ($mr['web_url'] ?? '');
    $sha   = substr((string) ($mr['sha'] ?? ''), 0, 7);

    $what = match ($event['type']) {
        'reviewer'  => '請審核 MR',
        'stale'     => "你的核准因新 commit（{$sha}）失效，" . reapproveSteps($domainTouched) . '：MR',
        'mergeable' => '這張 MR 已符合合併條件，可以按合併了：MR',
        default     => 'MR 需要你處理',
    };

    return "{$who} {$what} !{$iid}：{$title}\n{$url}";
}

/**
 * 處理一張 MR。回傳 Slack 是否全部送出。
 *
 * @param array{bypass_label: string, domains: list<array<string, mixed>>} $rules
 * @param array<string, string>                                            $userMap
 */
function processMr(string $project, int $iid, string $botUser, array $rules, array $userMap, ?DateTimeImmutable $since): bool
{
    $mr    = gitlabApi('GET', "/projects/{$project}/merge_requests/{$iid}");
    $notes = gitlabApi('GET', "/projects/{$project}/merge_requests/{$iid}/notes", ['sort' => 'asc', 'order_by' => 'created_at'], null, true);
    echo "MR !{$iid}：" . (string) ($mr['title'] ?? '') . "\n";

    $paths = approvalNeedsChangedPaths($rules)
        ? changedPaths((string) ($mr['diff_refs']['base_sha'] ?? ''), (string) ($mr['sha'] ?? ''))
        : ['*'];
    $domains  = approvalTouchedDomains($rules, $paths, approvalLabelNames($mr));
    $approval = mrInterventionApproval($mr, $notes, approvalReviewerUnion($domains));
    $approvalNeeded = $domains !== [] || mrInterventionAssignedReviewers($mr) !== [];

    $found    = mrInterventionFindState($notes, $botUser);
    $state    = $found['state'] ?? mrInterventionEmptyState();
    $baseline = $since === null
        || ($found === null && new DateTimeImmutable((string) ($mr['created_at'] ?? 'now')) < $since);
    $result   = mrInterventionDetect($mr, $approval, $state, $baseline);
    if ($baseline) {
        echo "  只建立紀錄、不通知（" . ($since === null ? '未設定 MR_NOTIFY_SINCE' : '上線前就開著的 MR') . "）\n";
    }

    $slackOk = true;
    $next    = $state;
    foreach ($result['events'] as $event) {
        $note = eventNoteBody($event, $mr, $notes, $domains !== [], $approvalNeeded);
        if ($note !== null) {
            if (isDryRun()) {
                echo "  --- 未寫 MR 留言（試跑）---\n  {$note}\n";
            } else {
                try {
                    gitlabApi('POST', "/projects/{$project}/merge_requests/{$iid}/notes", [], ['body' => $note]);
                } catch (RuntimeException $e) {
                    // 留言沒送出就不記，下次掃描再試；Slack 也先不發，免得 Slack 有、MR 上沒有
                    echo "  ✘ MR 留言送出失敗（{$event['type']}）：" . $e->getMessage() . "\n";
                    $slackOk = false;
                    continue;
                }
            }
        }
        $slackOk = sendSlack(eventSlackText($event, $mr, $userMap, $domains !== [])) && $slackOk;
        $next    = mrInterventionMergeState($next, $result['state'], $event['type']);
        echo "  已通知 {$event['type']}：" . implode('、', $event['to']) . "\n";
    }
    if ($baseline) {
        $next = $result['state'];
    }

    if ($next === $state && $found !== null) {
        return $slackOk;
    }
    if (isDryRun()) {
        echo "  --- 未更新紀錄留言（試跑）---\n";

        return $slackOk;
    }
    $body = mrInterventionStateBody($next);
    $found === null
        ? gitlabApi('POST', "/projects/{$project}/merge_requests/{$iid}/notes", [], ['body' => $body])
        : gitlabApi('PUT', "/projects/{$project}/merge_requests/{$iid}/notes/{$found['note_id']}", [], ['body' => $body]);

    return $slackOk;
}

function main(): int
{
    if ((string) getenv('APPROVAL_BOT_TOKEN') === '') {
        echo "未設定 APPROVAL_BOT_TOKEN，略過 MR 介入通知。\n";

        return 0;
    }
    $project = rawurlencode((string) getenv('CI_PROJECT_ID'));
    $rules   = approvalLoadRules(RULES_FILE);

    $mapBroken = false;
    try {
        $userMap = mrInterventionParseUserMap((string) getenv('SLACK_USER_MAP'));
    } catch (RuntimeException $e) {
        // 對照表壞掉時照常通知、只是不 @，比整個 job 停掉好；job 最後以失敗結束，讓人看到警告
        echo "✘ " . $e->getMessage() . "，這次 Slack 一律只寫帳號、不 @\n";
        $userMap   = [];
        $mapBroken = true;
    }

    $sinceRaw = (string) getenv('MR_NOTIFY_SINCE');
    $since    = $sinceRaw !== '' ? new DateTimeImmutable($sinceRaw) : null;

    $botUser = (string) (gitlabApi('GET', '/user')['username'] ?? '');
    if ($botUser === '') {
        throw new RuntimeException('查不到 APPROVAL_BOT_TOKEN 對應的帳號，無法辨認紀錄留言');
    }

    $mrs    = gitlabApi('GET', "/projects/{$project}/merge_requests", ['state' => 'opened'], null, true);
    $failed = 0;
    foreach ($mrs as $mr) {
        $iid = (int) ($mr['iid'] ?? 0);
        try {
            if (!processMr($project, $iid, $botUser, $rules, $userMap, $since)) {
                $failed++;
            }
        } catch (Throwable $e) {
            // 一張 MR 出錯不影響其他 MR
            echo "  ✘ MR !{$iid} 處理失敗：" . $e->getMessage() . "\n";
            $failed++;
        }
    }
    echo "掃描完成：開著的 MR " . count($mrs) . " 張，處理失敗 {$failed} 張。\n";

    return $failed === 0 && !$mapBroken ? 0 : 1;
}

try {
    exit(main());
} catch (Throwable $e) {
    echo "✘ " . $e->getMessage() . "\n";
    exit(1);
}
