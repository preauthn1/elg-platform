<?php

declare(strict_types=1);

/**
 * PM 確認檢查的 CI 入口，兩種模式：
 *
 *   php .gitlab-ci/pm-confirm/pm-confirm-check.php         合進預設分支（master）的 MR pipeline，job「PM 確認檢查」，
 *                                                          自動執行，看需求主單目前的標籤。
 *   php .gitlab-ci/pm-confirm/pm-confirm-check.php audit   預設分支的 push pipeline（合併後），job「合併後檢查 PM 確認」。
 *                                                          只改 MR 描述不會重跑 MR pipeline，所以合併後回頭確認「合併當下」
 *                                                          需求主單有沒有「0. PM確認」：沒通過、查不到 MR 或合併時間、
 *                                                          或檢查本身失敗，都發 Slack。只能事後發現，擋不了合併。
 *
 * 步驟照 release-workflow 參考文件「PM 確認檢查」：先看能不能豁免 → 收集需求單 → 子任務換主單 →
 * 讀團隊名單 → 逐張檢查主單 → 印出固定格式的報告。兩種模式走同一套步驟（evaluate()），差別在：
 * - commit 從哪裡來：MR 模式列 origin/<目標分支>..HEAD；合併後模式列這張 MR 帶進預設分支的 commit
 *   （合併 commit 的第一個 parent 到合併 commit，見 .gitlab-ci/lib/git.php 的 mergedMrCommits()）。
 * - squash 合併時 MR 自己的那幾顆 commit 不在預設分支上，看不出它們的 commit 類型，合併後模式不套用 commit 類型的豁免，照常檢查主單。
 * - 主單怎麼判：MR 模式看目前的標籤；合併後模式用合併時間以前的標籤異動紀錄重建合併當下的狀態
 *   （合併後才補貼不算、合併後才移除不影響），見 lib/pm-confirm.php。
 * PM 確認專屬的判定規則在 lib/pm-confirm.php；與其他合併關卡共用的判定（豁免、收集工單、名單格式）在
 * .gitlab-ci/lib/merge-gate.php，呼叫 GitLab 的共用程式在 .gitlab-ci/lib/gitlab-api.php，git 操作在
 * .gitlab-ci/lib/git.php，發 Slack 在 .gitlab-ci/lib/slack.php。
 * 任何一步呼叫 GitLab 或 git 失敗都算不通過，並寫出是哪一步，不當成通過。
 *
 * 權杖：讀工單與名單用專案 CI 變數 PM_CONFIRM_TOKEN（唯讀 read_api 權杖，見 gitlabToken()），
 *   因為 CI_JOB_TOKEN 讀不到 target/tickets 的工單本體、留言、標籤異動與父子關係。
 *   讀本專案的 MR 描述用 CI_JOB_TOKEN：PM_CONFIRM_TOKEN 的帳號只加進工單與名單那三個專案，讀不到本專案。
 *
 * 生效開關：PM_CONFIRM_ENFORCE = "true" 是正式模式（CI 設定讓沒通過的 MR 不能合併）；
 *   其他值或沒設是試行模式，只顯示警告。兩種模式都印出完整報告、沒通過都以 1 結束，
 *   差別只在 pm-confirm.yml 的 allow_failure。切換只改專案 CI 變數，不必改程式碼。
 *   MR 模式不直接讀 PM_CONFIRM_ENFORCE：pm-confirm.yml 的 rules 在建立 pipeline 時把模式寫進
 *   PM_CONFIRM_BLOCKING，失敗提示才會跟這條 pipeline 實際擋不擋一致。
 *   合併後模式直接讀 PM_CONFIRM_ENFORCE：正式模式才發 Slack，試行模式只印在 job log（試行期發了只是洗版）。
 *
 * 用到的 CI 變數：CI_API_V4_URL、CI_PROJECT_ID、CI_JOB_TOKEN、PM_CONFIRM_TOKEN；
 *   MR 模式另用 CI_MERGE_REQUEST_IID、CI_MERGE_REQUEST_TARGET_BRANCH_NAME、CI_MERGE_REQUEST_SOURCE_BRANCH_NAME、
 *   PM_CONFIRM_BLOCKING；
 *   合併後模式另用 CI_COMMIT_SHA、CI_DEFAULT_BRANCH、CI_JOB_URL、PM_CONFIRM_ENFORCE、SLACK_BOT_TOKEN、
 *   SLACK_REVIEW_CHANNEL_ID（「待審 MR」頻道；權杖或頻道沒設定就只印在 job log）。
 * 本機試跑：PM_CONFIRM_DRY_RUN=1 時合併後模式只印訊息、不送 Slack。
 */

require __DIR__ . '/lib/pm-confirm.php';
require __DIR__ . '/../lib/gitlab-api.php';
require __DIR__ . '/../lib/git.php';
require __DIR__ . '/../lib/slack.php';

const GATE_CHECK_NAME = 'PM 確認檢查';
const AUDIT_SLACK_CHANNEL_VAR = 'SLACK_REVIEW_CHANNEL_ID';

/**
 * 讀工單與名單用的權杖。PM_CONFIRM_TOKEN 的帳號刻意不加進本專案，權限只留在工單與名單那三個專案；
 * 讀本專案的 MR 改用 gitlabJobAuth()（CI_JOB_TOKEN）。
 */
function gitlabToken(): string
{
    $token = [REDACTED] getenv('PM_CONFIRM_TOKEN');
    if ($token === '') {
        throw new RuntimeException(
            '沒有設定專案 CI 變數 PM_CONFIRM_TOKEN（唯讀 read_api 權杖）。'
            . 'CI_JOB_TOKEN 讀不到 target/tickets 的工單與標籤異動，請維護者在專案的 Settings → CI/CD → Variables 加上這個變數。',
        );
    }

    return $token;
}

/**
 * 讀 GFF-Marketplace main 上的團隊名單。回傳 PM 組的使用者 ID 與名單版本（最後修改這份檔案的 commit）。
 *
 * @return array{pm_ids: list<int>, commit: string}
 */
function loadRoster(): array
{
    $roster = loadTeamRoster(gitlabToken());

    return [
        'pm_ids' => pmConfirmPmUserIds($roster['members']),
        'commit' => $roster['commit'],
    ];
}

/** @return array{0: int, 1: string} 結束碼與報告 */
function runCheck(): array
{
    $iid = requireEnv('CI_MERGE_REQUEST_IID');
    $project = rawurlencode(requireEnv('CI_PROJECT_ID'));
    $target = requireEnv('CI_MERGE_REQUEST_TARGET_BRANCH_NAME');
    $source = requireEnv('CI_MERGE_REQUEST_SOURCE_BRANCH_NAME');
    // 先確認權杖：沒設時連 hotfix 也照樣失敗，設定漏了才看得出來
    gitlabToken();

    return evaluate(
        $source,
        static fn (bool $withMerges): array => mrCommits($target, $withMerges),
        static fn (): string => (string) (gitlabGet("/projects/{$project}/merge_requests/{$iid}", gitlabJobAuth())['description'] ?? ''),
        true,
        null,
    );
}

/**
 * 兩種模式共用的檢查步驟。
 *
 * @param  callable(bool): list<array{subject: string, body: string}>  $commits  列出 commit；參數 true 時含合併用的 commit
 * @param  callable(): string  $description  讀 MR 描述
 * @param  bool  $typeExemptible  能不能套用 commit 類型的豁免（squash 合併後看不出 MR 自己那幾顆 commit 的類型時為 false）
 * @param  ?string  $mergedAt  合併後模式傳 MR 的合併時間，主單改看合併當下的狀態；MR 模式傳 null
 * @return array{0: int, 1: string} 結束碼與報告
 */
function evaluate(string $source, callable $commits, callable $description, bool $typeExemptible, ?string $mergedAt): array
{
    // 1. 豁免判斷
    if (gateIsHotfixBranch($source)) {
        return [0, pmConfirmReport('豁免（' . gateExemptReason($source, []) . '）', [], null)];
    }
    if ($typeExemptible) {
        $subjects = array_column(step('第 1 步（列出 MR 的 commit）', static fn (): array => $commits(false)), 'subject');
        $exempt = gateExemptReason($source, $subjects);
        if ($exempt !== null) {
            return [0, pmConfirmReport("豁免（{$exempt}）", [], null)];
        }
    }

    // 2. 收集需求單：MR 描述＋每顆 commit 的標題與內文
    $texts = [step('第 2 步（讀 MR 描述）', $description)];
    $allCommits = step('第 2 步（列出 MR 的 commit，含合併用的 commit）', static fn (): array => $commits(true));
    foreach ($allCommits as $commit) {
        $texts[] = $commit['subject'] . "\n" . $commit['body'];
    }
    $found = gateCollectTickets($texts);

    if ($found['tickets'] === [] && $found['ticket_tasks'] === []) {
        if ($found['op_tickets'] === []) {
            return [1, pmConfirmReport('不通過', ['找不到需求單連結'], null)];
        }

        return checkOpTicketsOnly($found['op_tickets']);
    }

    // 3. 子任務換主單
    $lines = [];
    $failed = false;
    $mainIids = $found['tickets'];
    foreach ($found['ticket_tasks'] as $taskIid) {
        $reason = '';
        try {
            $parent = parentTicketOf($taskIid, gitlabToken());
        } catch (Throwable $e) {
            $parent = null;
            $reason = '（' . $e->getMessage() . '）';
        }
        if ($parent === null) {
            $lines[] = '子任務 ' . TICKET_TASKS_PROJECT . "#{$taskIid}：找不到主單{$reason}";
            $failed = true;

            continue;
        }
        $mainIids[] = $parent;
    }
    $mainIids = array_values(array_unique($mainIids));
    sort($mainIids);

    // 4. 讀團隊名單
    $roster = step('第 4 步（讀團隊名單 ' . ROSTER_PROJECT . ' ' . ROSTER_REF . ':' . ROSTER_FILE . '）', static fn (): array => loadRoster());

    // 5. 逐張檢查主單：MR 模式看目前的標籤，合併後模式只看合併當下（不必讀主單本體）
    $tickets = rawurlencode(TICKETS_PROJECT);
    foreach ($mainIids as $mainIid) {
        $stepName = '第 5 步（讀主單 ' . TICKETS_PROJECT . "#{$mainIid}）";
        $issue = $mergedAt === null
            ? step($stepName, static fn (): array => gitlabGet("/projects/{$tickets}/issues/{$mainIid}", gitlabPrivateAuth(gitlabToken())))
            : [];
        $events = step($stepName, static fn (): array => gitlabGet("/projects/{$tickets}/issues/{$mainIid}/resource_label_events", gitlabPrivateAuth(gitlabToken()), [], true));
        $result = $mergedAt === null
            ? pmConfirmJudgeMainTicket($mainIid, (array) ($issue['labels'] ?? []), $events, $roster['pm_ids'])
            : pmConfirmJudgeAtMerge($mainIid, $events, $roster['pm_ids'], $mergedAt);
        $lines[] = $result['line'];
        $failed = $failed || !$result['ok'];
    }

    return [$failed ? 1 : 0, pmConfirmReport($failed ? '不通過' : '通過', $lines, $roster['commit'])];
}

/**
 * 只附線上問題單：每張都查得到才豁免（逐張確認存在的做法見 missingOpTickets()）。
 *
 * @param  list<int>  $opIids
 * @return array{0: int, 1: string}
 */
function checkOpTicketsOnly(array $opIids): array
{
    $missing = missingOpTickets($opIids, gitlabToken(), '第 2 步');
    if ($missing !== []) {
        return [1, pmConfirmReport('不通過', $missing, null)];
    }
    $refs = implode('、', array_map(static fn (int $n): string => OP_TICKETS_PROJECT . "#{$n}", $opIids));

    return [0, pmConfirmReport("豁免（只附線上問題單 {$refs}）", [], null)];
}

/**
 * 合併後模式：找出這次 push 合併的 MR，照 evaluate() 的步驟判斷合併當下有沒有通過。
 * 沒通過、查不到 MR 或合併時間、檢查本身失敗，都發 Slack；回傳 sendAuditAlert() 的結束碼，通過時回傳 0。
 */
function runAudit(): int
{
    $project = rawurlencode(requireEnv('CI_PROJECT_ID'));
    $sha = requireEnv('CI_COMMIT_SHA');
    $branch = requireEnv('CI_DEFAULT_BRANCH');
    gitlabToken();

    // 同一個 commit 可能關聯多張 MR，只取已合併進預設分支的那張
    $mrs = array_values(array_filter(
        step('找這次合併的 MR', static fn (): array => gitlabGet("/projects/{$project}/repository/commits/{$sha}/merge_requests", gitlabJobAuth())),
        static fn ($m): bool => is_array($m) && ($m['state'] ?? '') === 'merged' && ($m['target_branch'] ?? '') === $branch,
    ));
    if ($mrs === []) {
        return sendAuditAlert([
            '⚠️ 合併後檢查 PM 確認：找不到這次 push 對應的已合併 MR，請人工確認這次合併當下需求主單有沒有「' . PM_CONFIRM_LABEL . '」',
            "commit：{$sha}",
            (string) getenv('CI_JOB_URL'),
        ]);
    }
    // 關聯清單的欄位可能不完整，合併時間、來源分支、描述一律以單張 MR 的完整資料為準
    $iid = (int) $mrs[0]['iid'];
    $mr = step("讀 MR !{$iid}", static fn (): array => gitlabGet("/projects/{$project}/merge_requests/{$iid}", gitlabJobAuth()));
    $head = [
        "MR !{$iid}：" . (string) ($mr['title'] ?? ''),
        '作者：@' . (string) ($mr['author']['username'] ?? ''),
        (string) ($mr['web_url'] ?? ''),
        '',
    ];

    // 沒有合併時間就無法排除「合併後才補貼的標籤」，不能宣告通過
    $mergedAt = $mr['merged_at'] ?? null;
    if (!is_string($mergedAt) || $mergedAt === '') {
        return sendAuditAlert(['⚠️ 合併後檢查 PM 確認無法確認合併時間，請人工確認這張 MR 合併當下需求主單有沒有「' . PM_CONFIRM_LABEL . '」', ...$head]);
    }

    try {
        $source = (string) ($mr['source_branch'] ?? '');
        if ($source === '') {
            throw new RuntimeException("MR !{$iid} 缺少來源分支");
        }
        // squash 合併時預設分支上只剩壓成一顆的 commit，看不出 MR 自己那幾顆 commit 的類型，不套用 commit 類型的豁免
        $squashed = ($mr['squash_commit_sha'] ?? null) !== null || ($mr['squash'] ?? false) === true;
        [$code, $report] = evaluate(
            $source,
            static fn (bool $withMerges): array => mergedMrCommits($sha, $mr, $withMerges),
            static fn (): string => (string) ($mr['description'] ?? ''),
            !$squashed,
            $mergedAt,
        );
    } catch (Throwable $e) {
        return sendAuditAlert([
            '⚠️ 合併後的 PM 確認檢查執行失敗，請人工確認這張 MR 合併當下需求主單有沒有「' . PM_CONFIRM_LABEL . '」',
            ...$head,
            '原因：' . $e->getMessage(),
            (string) getenv('CI_JOB_URL'),
        ]);
    }

    $mergedLine = '合併時間：' . gateTaipeiTime($mergedAt) . ' 台北時間，以下是合併當下的狀態：';
    if ($code === 0) {
        echo "MR !{$iid} {$mergedLine}\n{$report}";

        return 0;
    }

    return sendAuditAlert([
        '🚨 MR 合併當下，需求主單沒有通過 PM 確認',
        ...$head,
        $mergedLine,
        ...explode("\n", rtrim($report, "\n")),
        '',
        '請 PM 確認這張 MR 的需求，有問題就開修正 MR。',
    ]);
}

/**
 * 合併後模式發 Slack 到「待審 MR」頻道（SLACK_REVIEW_CHANNEL_ID）。
 * 正式模式（PM_CONFIRM_ENFORCE = "true"）才送出；試行模式或本機試跑（PM_CONFIRM_DRY_RUN=1）只印在 job log。
 */
function sendAuditAlert(array $lines): int
{
    return gateSendSlack($lines, AUDIT_SLACK_CHANNEL_VAR, getenv('PM_CONFIRM_ENFORCE') === 'true' && getenv('PM_CONFIRM_DRY_RUN') !== '1');
}

$mode = $argv[1] ?? '';
if ($mode === 'audit') {
    define('GATE_ENV_HINT', '合併後檢查 PM 確認只能在預設分支（master）的 push pipeline 執行');
    try {
        exit(runAudit());
    } catch (Throwable $e) {
        // 合併後檢查失敗時沒人會去看預設分支的 pipeline，改發 Slack 才會被注意到
        echo '✘ ' . $e->getMessage() . "\n";
        sendAuditAlert([
            '⚠️ 合併後的 PM 確認檢查執行失敗，請人工確認這次合併當下需求主單有沒有「' . PM_CONFIRM_LABEL . '」',
            'commit：' . (string) getenv('CI_COMMIT_SHA'),
            '原因：' . $e->getMessage(),
            (string) getenv('CI_JOB_URL'),
        ]);
        exit(1);
    }
}
if ($mode !== '') {
    echo "用法：php .gitlab-ci/pm-confirm/pm-confirm-check.php [audit]\n";
    exit(1);
}

// pm-confirm.yml 的 rules 在建立 pipeline 時依 PM_CONFIRM_ENFORCE 設好這個值，跟 allow_failure 同步
$enforced = getenv('PM_CONFIRM_BLOCKING') === 'true';
try {
    [$code, $report] = runCheck();
} catch (Throwable $e) {
    // 主單或名單查不到（404）也走這裡：任何一步失敗都算不通過
    [$code, $report] = [1, pmConfirmReport('不通過', [$e->getMessage()], null)];
}

echo $report;
if ($code !== 0) {
    echo "\n";
    echo $enforced
        ? "沒有通過 PM 確認檢查，這張 MR 不能合併。\n"
        : "目前是試行期，不會擋合併；正式生效後沒通過就不能合併。\n";
    echo '常見補法：MR 描述補上需求單連結，或請 PM 在需求主單貼上「' . PM_CONFIRM_LABEL . "」標籤（必須由 PM 名單上的人加）。\n";
    echo "條件補齊後，在 MR 頁面按「Run pipeline」開一條新的 pipeline，不必重開 MR。\n";
}
exit($code);
