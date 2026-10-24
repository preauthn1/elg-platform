<?php

declare(strict_types=1);

/**
 * MR 審批制度的 CI 入口，三種模式：
 *
 *   php scripts/approval-check.php check   MR pipeline 的手動 job「需要審核人核准」。
 *                                          MR 沒動到名單裡任何領域（路徑或標籤）時直接通過。
 *                                          動到時，審核人按核准後按 ▶ 執行；有效核准才通過。沒有有效核准時，
 *                                          貼了緊急標籤就放行，否則失敗並列出可以找誰。
 *   php scripts/approval-check.php audit   main 的 push pipeline（合併後），job「合併後檢查核准」。社群版不能在合併當下再檢查一次，
 *                                          所以合併後回頭確認：沒有有效核准（不論有沒有緊急標籤）、
 *                                          查不到合併時間、或檢查本身失敗，都發 Slack。
 *   php scripts/approval-check.php notify-stale
 *                                          MR pipeline 的自動 job「提醒重新核准」，只提醒、不擋合併。社群版推新 commit
 *                                          不會移除核准，畫面上仍顯示「已核准」；這裡由 bot 在 MR 留言 @ 核准已失效的審核人，
 *                                          請他們撤銷核准、重新核准，再按 ▶。已有有效核准、沒有失效的核准、
 *                                          或這次推送已提醒過（留言尾端的隱藏標記帶同一個 commit SHA）時不留言。
 *
 * 這套檢查防疏忽、不防刻意繞過：CI 設定、名單與本腳本都在同一個 repo，見 scripts/lib/approval.php 開頭。
 *
 * 判定規則在 scripts/lib/approval.php，名單在 .gitlab/approval-rules.json。
 *
 * 生效開關：APPROVAL_ENFORCE = "true" 是正式模式；其他值是試行模式，check 只警告（CI 設定讓它不擋合併），
 *   audit 不發 Slack。切換只改專案 CI 變數，不必改程式碼。
 *
 * 用到的 CI 變數：CI_API_V4_URL、CI_PROJECT_ID、CI_JOB_TOKEN、APPROVAL_ENFORCE；
 *   check 另用 CI_MERGE_REQUEST_IID、CI_MERGE_REQUEST_DIFF_BASE_SHA；
 *   audit 另用 CI_COMMIT_SHA、SLACK_BOT_TOKEN、SLACK_CHANNEL_ID（未設定就只印在 job log）；
 *   notify-stale 另用 CI_MERGE_REQUEST_IID、CI_MERGE_REQUEST_DIFF_BASE_SHA、CI_COMMIT_SHA、APPROVAL_BOT_TOKEN
 *   （Project Access Token，api scope；CI_JOB_TOKEN 不能留言，所以另用這個 token。未設定就略過提醒）。
 *   notify-stale 不看 APPROVAL_ENFORCE：只有真的按過核准的人會收到提醒，試行模式也不會洗版。
 * 變更檔案清單用 git 算（scripts/lib/git-changes.php）：CI_JOB_TOKEN 讀得到 MR 本體與留言，讀不到變更檔案清單
 *   （實打 diffs、changes、compare、檔案樹都回 404）。CI 的映像檔沒有 git，由 .gitlab-ci.yml 在 job 開始時安裝。
 * 本機試跑：APPROVAL_DRY_RUN=1 時 audit 只印訊息、不送 Slack。
 */

require __DIR__ . '/lib/approval.php';
require __DIR__ . '/lib/git-changes.php';

const RULES_FILE = __DIR__ . '/../.gitlab/approval-rules.json';

/**
 * 呼叫 GitLab API（JOB-TOKEN），自動翻頁直到拿完。HTTP 錯誤直接丟例外：
 * 查不到資料時寧可讓檢查失敗，也不要誤判成「沒有核准需求」而放行。
 *
 * @return list<array<string, mixed>>|array<string, mixed>
 */
function gitlabGet(string $path, array $query = [], bool $paginate = false): array
{
    $base  = rtrim((string) getenv('CI_API_V4_URL'), '/');
    $token = [REDACTED] getenv('CI_JOB_TOKEN');
    $all   = [];
    $page  = 1;

    do {
        $q   = $paginate ? $query + ['per_page' => 100, 'page' => $page] : $query;
        $url = "{$base}{$path}" . ($q !== [] ? '?' . http_build_query($q) : '');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["JOB-TOKEN: [REDACTED]"],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $body = (string) curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($err !== '' || $code >= 400) {
            throw new RuntimeException("GitLab API 失敗（{$path}，HTTP {$code}）：" . ($err !== '' ? $err : mb_substr($body, 0, 300)));
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException("GitLab API 回傳不是 JSON（{$path}）");
        }
        if (!$paginate) {
            return $data;
        }
        array_push($all, ...$data);
        $page++;
    } while (count($data) === 100);

    return $all;
}

/**
 * 以 APPROVAL_BOT_TOKEN（PRIVATE-TOKEN）POST 到 GitLab API，送 JSON。HTTP 錯誤直接丟例外，
 * 留言沒送出去時 job 要失敗，才看得出提醒沒發。
 *
 * @return array<string, mixed>
 */
function gitlabPost(string $path, array $body): array
{
    $base  = rtrim((string) getenv('CI_API_V4_URL'), '/');
    $token = [REDACTED] getenv('APPROVAL_BOT_TOKEN');
    $ch    = curl_init("{$base}{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ["PRIVATE-TOKEN: [REDACTED]", 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = (string) curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    if ($err !== '' || $code >= 400) {
        throw new RuntimeException("GitLab API 失敗（{$path}，HTTP {$code}）：" . ($err !== '' ? $err : mb_substr($resp, 0, 300)));
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new RuntimeException("GitLab API 回傳不是 JSON（{$path}）");
    }

    return $data;
}

function runCheck(array $rules): int
{
    $iid = (string) getenv('CI_MERGE_REQUEST_IID');
    if ($iid === '') {
        throw new RuntimeException('找不到 CI_MERGE_REQUEST_IID，核准檢查只能在 MR pipeline 執行');
    }
    $project = rawurlencode((string) getenv('CI_PROJECT_ID'));
    $mr      = gitlabGet("/projects/{$project}/merge_requests/{$iid}");
    $author  = (string) ($mr['author']['username'] ?? '');

    $paths   = approvalNeedsChangedPaths($rules) ? changedPaths((string) getenv('CI_MERGE_REQUEST_DIFF_BASE_SHA'), 'HEAD') : ['*'];
    $domains = approvalTouchedDomains($rules, $paths, approvalLabelNames($mr));
    if ($domains === []) {
        echo "這張 MR 沒有動到需要核准的路徑，也沒有貼需要核准的標籤，直接通過。\n";

        return 0;
    }

    $notes  = gitlabGet("/projects/{$project}/merge_requests/{$iid}/notes", ['sort' => 'asc', 'order_by' => 'created_at'], true);
    $result = approvalEvaluate($notes, $author, approvalReviewerUnion($domains), (string) ($mr['created_at'] ?? ''));

    $names = implode('、', array_column($domains, 'name'));
    if ($result['approved']) {
        echo "✔ 核准有效（領域：{$names}；核准人：" . implode('、', $result['approvers']) . "）\n";

        return 0;
    }

    // 緊急標籤只在沒有有效核准時才用得上；有人審過就照正常流程通過，不必事後補審
    if (approvalHasLabel($mr, $rules['bypass_label'])) {
        echo "⚠ 沒有有效核准，但 MR !{$iid} 貼了「{$rules['bypass_label']}」標籤，緊急放行。\n";
        echo "  合併後會發 Slack 提醒，審核人須在 1 個工作天內補審。\n";

        return 0;
    }

    echo isEnforced()
        ? "✘ 還沒有有效的核准。\n\n"
        : "⚠ 還沒有有效的核准。目前是試行期，不會擋合併；正式生效後沒有核准就不能合併。\n\n";
    echo "這張 MR 動到 " . count($domains) . " 個領域，請找作者以外的任一位審核人核准：\n";
    echo implode("\n", approvalWhoToAsk($domains, $author)) . "\n\n";
    echo "核准後回到這個 job，按右上角的「重試」即可。\n";
    echo "作者自己按的核准不算。\n";
    if ($result['reset_by_push']) {
        echo "最後一次推 commit 是 {$result['since']}，在那之前的核准都已失效，請先撤銷核准，再重新核准。\n";
    }

    return 1;
}

/**
 * 推新 commit 讓既有核准失效時，由 bot 在 MR 留言 @ 那幾位審核人。只提醒、不擋合併：
 * 真正的閘門仍是「需要審核人核准」。去重靠留言尾端帶 commit SHA 的隱藏標記，
 * 手動重跑 pipeline 不會再留一次，每次新推送各提醒一次。
 */
function runNotifyStale(array $rules): int
{
    if ((string) getenv('APPROVAL_BOT_TOKEN') === '') {
        echo "未設定 APPROVAL_BOT_TOKEN，略過提醒。\n";

        return 0;
    }
    $iid = (string) getenv('CI_MERGE_REQUEST_IID');
    if ($iid === '') {
        throw new RuntimeException('找不到 CI_MERGE_REQUEST_IID，提醒重新核准只能在 MR pipeline 執行');
    }
    $sha = (string) getenv('CI_COMMIT_SHA');
    if ($sha === '') {
        throw new RuntimeException('找不到 CI_COMMIT_SHA，無法標記這次推送是否已提醒過');
    }
    $project = rawurlencode((string) getenv('CI_PROJECT_ID'));
    $mr      = gitlabGet("/projects/{$project}/merge_requests/{$iid}");
    $author  = (string) ($mr['author']['username'] ?? '');

    $paths   = approvalNeedsChangedPaths($rules) ? changedPaths((string) getenv('CI_MERGE_REQUEST_DIFF_BASE_SHA'), 'HEAD') : ['*'];
    $domains = approvalTouchedDomains($rules, $paths, approvalLabelNames($mr));
    if ($domains === []) {
        echo "這張 MR 沒有動到需要核准的路徑，也沒有貼需要核准的標籤，不需要提醒。\n";

        return 0;
    }

    $notes  = gitlabGet("/projects/{$project}/merge_requests/{$iid}/notes", ['sort' => 'asc', 'order_by' => 'created_at'], true);
    $result = approvalEvaluate($notes, $author, approvalReviewerUnion($domains), (string) ($mr['created_at'] ?? ''));
    if ($result['approved']) {
        echo "已有有效核准（" . implode('、', $result['approvers']) . "），不需要提醒。\n";

        return 0;
    }
    if ($result['stale'] === []) {
        echo "沒有失效的核准，不需要提醒。\n";

        return 0;
    }

    if (staleNoticeExists($notes, $sha)) {
        echo "這次推送（{$sha}）已提醒過，不再留言。\n";

        return 0;
    }

    $body = staleNoticeBody($result, $sha);
    try {
        gitlabPost("/projects/{$project}/merge_requests/{$iid}/notes", ['body' => $body]);
    } catch (RuntimeException $e) {
        echo "✘ 提醒留言送出失敗：" . $e->getMessage() . "\n";

        return 1;
    }
    echo "已提醒：" . implode('、', array_map(static fn (string $u): string => "@{$u}", $result['stale'])) . "\n";

    return 0;
}

/**
 * 提醒留言內文。時間轉成 Asia/Taipei 顯示；推送與每位的核准都在同一天時，推送只寫時分。
 *
 * @param array{stale: list<string>, stale_at: array<string, string>, since: string, since_by: string} $result
 */
function staleNoticeBody(array $result, string $sha): string
{
    $tz       = new DateTimeZone('Asia/Taipei');
    $pushedAt = (new DateTimeImmutable($result['since']))->setTimezone($tz);

    $parts    = [];
    $sameDay  = true;
    foreach ($result['stale'] as $user) {
        $approvedAt = (new DateTimeImmutable($result['stale_at'][$user]))->setTimezone($tz);
        $sameDay    = $sameDay && $approvedAt->format('Y-m-d') === $pushedAt->format('Y-m-d');
        $parts[]    = "@{$user} 你在 " . $approvedAt->format('Y-m-d H:i') . ' 的核准';
    }
    $pushTime = $pushedAt->format($sameDay ? 'H:i' : 'Y-m-d H:i');
    $shortSha = substr($sha, 0, 7);
    $reason   = $result['since_by'] !== ''
        ? "已因 @{$result['since_by']} 在 {$pushTime} 推的新 commit（{$shortSha}）失效。"
        : "已因新 commit（{$shortSha}）失效。";

    return implode('、', $parts) . "，{$reason}\n"
        . "重審後請：\n"
        . "1. MR 右上角按「撤銷核准」，再按「核准」（GitLab 社群版不會自動重置核准，要先撤銷才能重按）。\n"
        . "2. 到 pipeline 按「需要審核人核准」的 ▶。\n"
        . "\n"
        . staleMarker($sha);
}

function runAudit(array $rules): int
{
    $project = rawurlencode((string) getenv('CI_PROJECT_ID'));
    $sha     = (string) getenv('CI_COMMIT_SHA');

    // 同一個 commit 可能關聯多張 MR，只取已合併進 main 的那張（與 notify-merge.php 同一套判斷）
    $mrs = array_values(array_filter(
        gitlabGet("/projects/{$project}/repository/commits/{$sha}/merge_requests"),
        static fn (array $m): bool => ($m['state'] ?? '') === 'merged' && ($m['target_branch'] ?? '') === 'main',
    ));
    if ($mrs === []) {
        echo "此 commit 沒有對應已合併的 MR，略過合併後檢查。\n";

        return 0;
    }
    // 關聯清單的欄位可能不完整，合併時間、作者、標籤一律以單張 MR 的完整資料為準
    $iid    = (int) $mrs[0]['iid'];
    $mr     = gitlabGet("/projects/{$project}/merge_requests/{$iid}");
    $author = (string) ($mr['author']['username'] ?? '');
    $url    = (string) ($mr['web_url'] ?? '');
    $title  = (string) ($mr['title'] ?? '');
    $head   = ["MR !{$iid}：{$title}", "作者：@{$author}", $url, ''];

    $paths   = approvalNeedsChangedPaths($rules) ? changedPaths(mergeBase($sha, $mr), $sha) : ['*'];
    $domains = approvalTouchedDomains($rules, $paths, approvalLabelNames($mr));
    if ($domains === []) {
        echo "MR !{$iid} 沒有動到需要核准的路徑，也沒有貼需要核准的標籤，略過合併後檢查。\n";

        return 0;
    }

    // 沒有合併時間就無法排除「合併後才補的核准」，不能宣告有效
    $mergedAt = $mr['merged_at'] ?? null;
    if (!is_string($mergedAt) || $mergedAt === '') {
        return sendAuditAlert(["⚠️ 合併後檢查無法確認合併時間，請人工確認這張 MR 合併前有沒有人核准", ...$head]);
    }

    $notes  = gitlabGet("/projects/{$project}/merge_requests/{$iid}/notes", ['sort' => 'asc', 'order_by' => 'created_at'], true);
    $result = approvalEvaluate($notes, $author, approvalReviewerUnion($domains), (string) ($mr['created_at'] ?? ''), $mergedAt);

    if ($result['approved']) {
        echo "✔ MR !{$iid} 合併時有有效核准（" . implode('、', $result['approvers']) . "）。\n";

        return 0;
    }

    if (approvalHasLabel($mr, $rules['bypass_label'])) {
        return sendAuditAlert([
            "⚠️ 緊急放行的 MR 已合併，請在 1 個工作天內補審",
            ...$head,
            '可以補審的人：',
            ...approvalWhoToAsk($domains, $author),
        ]);
    }

    return sendAuditAlert([
        "🚨 MR 在沒有有效核准的情況下合併了",
        ...$head,
        '常見原因：核准檢查通過後，審核人取消核准，但沒有把 MR 改回 Draft。',
        '請以下任一位補審，有問題就開修正 MR：',
        ...approvalWhoToAsk($domains, $author),
    ]);
}

/** 專案 CI 變數 APPROVAL_ENFORCE 等於 "true" 才是正式模式；其他值或沒設都是試行模式。 */
function isEnforced(): bool
{
    return getenv('APPROVAL_ENFORCE') === 'true';
}

/**
 * 發 Slack；試行模式、本機試跑、或沒設定憑證時只印出來（試行期大家還沒開始找人審，發了只是洗版）。
 * 回傳 0：警告送不出去也不擋 main 的 pipeline。
 */
function sendAuditAlert(array $lines): int
{
    $text    = implode("\n", $lines);
    $token   = [REDACTED] getenv('SLACK_BOT_TOKEN');
    $channel = (string) getenv('SLACK_CHANNEL_ID');

    if (!isEnforced() || getenv('APPROVAL_DRY_RUN') === '1' || $token === '' || $channel === '') {
        echo "--- 未送 Slack（試行模式、試跑或未設定 SLACK_BOT_TOKEN / SLACK_CHANNEL_ID）---\n{$text}\n--- END ---\n";

        return 0;
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
        echo "Slack 送出失敗：" . (curl_error($ch) ?: $resp) . "\n{$text}\n";

        return 1;
    }
    echo "已送出 Slack 警告：\n{$text}\n";

    return 0;
}

$mode = $argv[1] ?? '';
try {
    $rules = approvalLoadRules(RULES_FILE);
    exit(match ($mode) {
        'check' => runCheck($rules),
        'audit' => runAudit($rules),
        'notify-stale' => runNotifyStale($rules),
        default => throw new RuntimeException('用法：php scripts/approval-check.php check|audit|notify-stale'),
    });
} catch (Throwable $e) {
    echo "✘ " . $e->getMessage() . "\n";
    // 合併後檢查失敗時沒人會去看 main 的 pipeline，改發 Slack 才會被注意到
    if ($mode === 'audit') {
        sendAuditAlert([
            '⚠️ 合併後的核准檢查執行失敗，請人工確認這次合併前有沒有人核准',
            'commit：' . (string) getenv('CI_COMMIT_SHA'),
            '原因：' . $e->getMessage(),
            (string) getenv('CI_JOB_URL'),
        ]);
    }
    exit(1);
}
