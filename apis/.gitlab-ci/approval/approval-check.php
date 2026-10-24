<?php

declare(strict_types=1);

/**
 * MR 審批制度的 CI 入口，兩種模式：
 *
 *   php .gitlab-ci/approval/approval-check.php check   合進預設分支（master）的 MR pipeline，job「需要審核人核准」。
 *                                                      建立 pipeline 時自動跑；有效核准才通過。沒有有效核准時，
 *                                                      來源分支以 hotfix/ 開頭就緊急放行，否則失敗並列出可以找誰。
 *   php .gitlab-ci/approval/approval-check.php audit   預設分支的 push pipeline（合併後），job「合併後檢查核准」。
 *                                                      社群版不能在合併當下再檢查一次，所以合併後回頭確認：
 *                                                      沒有有效核准（不論是不是 hotfix 分支）、查不到合併時間、
 *                                                      或檢查本身失敗，都發 Slack。
 *   php .gitlab-ci/approval/approval-check.php sync    job「核准名單檢查」。比對名單與 approval.yml 的 rules: changes
 *                                                      路徑是否一致，不一致就失敗並列出差異。不呼叫 GitLab API。
 *
 * 這套檢查防疏忽、不防刻意繞過：CI 設定、名單與本腳本都在同一個 repo，見 lib/approval.php 開頭。
 *
 * 判定規則在 lib/approval.php，名單在 .gitlab/approval-rules.json。執行 git 的 gitRun()、合併後檢查的比對起點
 * gitMergedFrom()、「最後一次推 commit 的時間」gateLastPushAt() 與發 Slack 的 gateSendSlack() 和其他合併關卡共用，
 * 分別在 .gitlab-ci/lib/git.php、.gitlab-ci/lib/merge-gate.php 與 .gitlab-ci/lib/slack.php。
 *
 * 生效開關：APPROVAL_ENFORCE = "true" 是正式模式；其他值是試行模式，check 只警告（CI 設定讓它不擋合併），
 *   audit 不發 Slack。切換只改專案 CI 變數，不必改程式碼。
 *
 * 用到的 CI 變數：CI_API_V4_URL、CI_PROJECT_ID、CI_JOB_TOKEN、CI_DEFAULT_BRANCH、APPROVAL_ENFORCE；
 *   check 另用 CI_MERGE_REQUEST_IID、CI_MERGE_REQUEST_DIFF_BASE_SHA；
 *   audit 另用 CI_COMMIT_SHA、SLACK_BOT_TOKEN、SLACK_CHANNEL_ID（未設定就只印在 job log）。
 * 本機試跑：APPROVAL_DRY_RUN=1 時 audit 只印訊息、不送 Slack。
 */

require __DIR__ . '/lib/approval.php';
require __DIR__ . '/../lib/git.php';
require __DIR__ . '/../lib/slack.php';

const RULES_FILE = __DIR__ . '/../../.gitlab/approval-rules.json';
const CI_PATHS_FILE = __DIR__ . '/approval.yml';

/**
 * 呼叫 GitLab API（JOB-TOKEN），自動翻頁直到拿完。HTTP 錯誤直接丟例外：
 * 查不到資料時寧可讓檢查失敗，也不要誤判成「沒有核准需求」而放行。
 *
 * @return list<array<string, mixed>>|array<string, mixed>
 */
function gitlabGet(string $path, array $query = [], bool $paginate = false): array
{
    $base = rtrim((string) getenv('CI_API_V4_URL'), '/');
    $token = [REDACTED] getenv('CI_JOB_TOKEN');
    $all = [];
    $page = 1;

    do {
        $q = $paginate ? $query + ['per_page' => 100, 'page' => $page] : $query;
        $url = "{$base}{$path}" . ($q !== [] ? '?' . http_build_query($q) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["JOB-TOKEN: [REDACTED]"],
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = (string) curl_exec($ch);
        $err = curl_error($ch);
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

function gitHasCommit(string $rev): bool
{
    try {
        gitRun(['rev-parse', '--quiet', '--verify', $rev . '^{commit}']);

        return true;
    } catch (RuntimeException) {
        return false;
    }
}

/**
 * CI 是淺層 clone，需要的 commit 不在本機時補抓：完整 SHA 直接抓那個 commit，
 * 其他寫法（例如 <sha>^1）加深歷史後再找。補完仍找不到就丟例外。
 */
function ensureCommit(string $rev): void
{
    if (gitHasCommit($rev)) {
        return;
    }
    try {
        gitRun(preg_match('/^[0-9a-f]{40}$/', $rev) === 1
            ? ['fetch', '--quiet', 'origin', $rev]
            : ['fetch', '--quiet', '--deepen=200', 'origin']);
    } catch (RuntimeException) {
        // 抓不到就交給下面的檢查統一報錯
    }
    if (!gitHasCommit($rev)) {
        throw new RuntimeException("CI 的 git 歷史裡找不到 {$rev}，無法算出變更檔案");
    }
}

/**
 * 兩個 commit 之間的變更檔案，附上變更類型（A 新增、M 修改、D 刪除…）。
 * --no-renames：搬移檔案時新舊路徑都要列出，否則把列管的檔案搬到別處就不必核准。
 * -z：檔名原樣輸出；不加的話，中文檔名會被 git 加上引號與跳脫字元，比對不到任何領域。
 *
 * @return list<array{status: string, path: string}>
 */
function changedFiles(string $from, string $to): array
{
    if ($from === '' || $to === '') {
        throw new RuntimeException('缺少比對起點或終點，無法算出變更檔案');
    }
    ensureCommit($from);
    ensureCommit($to);
    $parts = explode("\0", rtrim(gitRun(['diff', '--no-renames', '--name-status', '-z', $from, $to]), "\0"));
    if ($parts === ['']) {
        return [];
    }
    if (count($parts) % 2 !== 0) {
        throw new RuntimeException('git diff 的輸出格式不如預期，無法算出變更檔案');
    }

    $files = [];
    for ($i = 0; $i < count($parts); $i += 2) {
        $files[] = ['status' => $parts[$i], 'path' => $parts[$i + 1]];
    }

    return $files;
}

/**
 * 算出需要核准的變更路徑。動到審核路徑、但只改了註解或排版的 PHP 檔（只限修改既有檔案）
 * 不算，列在 cosmetic 裡讓 job 紀錄看得到；新增、刪除的檔案與非 PHP 檔一律照常算。
 *
 * @return array{paths: list<string>, cosmetic: list<string>}
 */
function reviewPaths(array $rules, string $from, string $to): array
{
    if (!approvalNeedsChangedPaths($rules)) {
        return ['paths' => ['*'], 'cosmetic' => []];
    }

    $paths = [];
    $cosmetic = [];
    foreach (changedFiles($from, $to) as $file) {
        $path = $file['path'];
        if ($file['status'] === 'M'
            && str_ends_with($path, '.php')
            && approvalTouchedDomains($rules, [$path]) !== []
            && approvalPhpLogicUnchanged(gitRun(['show', "{$from}:{$path}"]), gitRun(['show', "{$to}:{$path}"]))
        ) {
            $cosmetic[] = $path;

            continue;
        }
        $paths[] = $path;
    }

    return ['paths' => $paths, 'cosmetic' => $cosmetic];
}

/** 只改註解或排版而不列入審核的檔案，印在 job 紀錄裡，讓人看得到哪些檔案被放過。 */
function printCosmetic(array $cosmetic): void
{
    if ($cosmetic === []) {
        return;
    }
    echo "以下檔案只改了註解或排版，不列入審核：\n";
    foreach ($cosmetic as $path) {
        echo "  ・{$path}\n";
    }
    echo "\n";
}

function runCheck(array $rules): int
{
    $iid = (string) getenv('CI_MERGE_REQUEST_IID');
    if ($iid === '') {
        throw new RuntimeException('找不到 CI_MERGE_REQUEST_IID，核准檢查只能在 MR pipeline 執行');
    }
    $project = rawurlencode((string) getenv('CI_PROJECT_ID'));
    $mr = gitlabGet("/projects/{$project}/merge_requests/{$iid}");
    $author = (string) ($mr['author']['username'] ?? '');

    $review = reviewPaths($rules, (string) getenv('CI_MERGE_REQUEST_DIFF_BASE_SHA'), 'HEAD');
    printCosmetic($review['cosmetic']);
    $domains = approvalTouchedDomains($rules, $review['paths']);
    if ($domains === []) {
        echo $review['cosmetic'] === []
            ? "這張 MR 沒有動到需要核准的路徑，直接通過。\n"
            : "這張 MR 動到審核路徑的地方都只改了註解或排版，不需要核准，直接通過。\n";

        return 0;
    }

    $notes = gitlabGet("/projects/{$project}/merge_requests/{$iid}/notes", ['sort' => 'asc', 'order_by' => 'created_at'], true);
    $result = approvalEvaluate($notes, $author, approvalReviewerUnion($domains), (string) ($mr['created_at'] ?? ''));

    $names = implode('、', array_column($domains, 'name'));
    if ($result['approved']) {
        echo "✔ 核准有效（領域：{$names}；核准人：" . implode('、', $result['approvers']) . "）\n";

        return 0;
    }

    // hotfix 放行只在沒有有效核准時才用得上；有人審過就照正常流程通過，不必事後補審
    if (approvalHotfixBypass($mr)) {
        echo "⚠ 沒有有效核准，但 MR !{$iid} 的來源分支「{$mr['source_branch']}」是 hotfix 分支，緊急放行。\n";
        echo "  合併後會發 Slack 提醒，審核人須在 1 個工作天內補審。\n";

        return 0;
    }

    echo isEnforced()
        ? "✘ 還沒有有效的核准。\n\n"
        : "⚠ 還沒有有效的核准。目前是試行期，不會擋合併；正式生效後沒有核准就不能合併。\n\n";
    echo '這張 MR 動到 ' . count($domains) . " 個領域，請找作者以外的任一位審核人核准：\n";
    echo implode("\n", approvalWhoToAsk($domains, $author)) . "\n\n";
    echo "核准後，在 MR 頁面按「Run pipeline」開一條新的 pipeline。\n";
    echo "作者自己按的核准不算。\n";
    if ($result['reset_by_push']) {
        echo "最後一次推 commit 是 {$result['since']}，在那之前的核准都已失效，需要重新核准。\n";
    }

    return 1;
}

function runAudit(array $rules): int
{
    $project = rawurlencode((string) getenv('CI_PROJECT_ID'));
    $sha = (string) getenv('CI_COMMIT_SHA');

    $branch = (string) getenv('CI_DEFAULT_BRANCH');
    if ($branch === '') {
        throw new RuntimeException('找不到 CI_DEFAULT_BRANCH，無法判斷 MR 是否合進預設分支');
    }

    // 同一個 commit 可能關聯多張 MR，只取已合併進預設分支的那張
    $mrs = array_values(array_filter(
        gitlabGet("/projects/{$project}/repository/commits/{$sha}/merge_requests"),
        static fn (array $m): bool => ($m['state'] ?? '') === 'merged' && ($m['target_branch'] ?? '') === $branch,
    ));
    if ($mrs === []) {
        echo "此 commit 沒有對應已合併的 MR，略過合併後檢查。\n";

        return 0;
    }
    // 關聯清單的欄位可能不完整，合併時間、作者、來源分支一律以單張 MR 的完整資料為準
    $iid = (int) $mrs[0]['iid'];
    $mr = gitlabGet("/projects/{$project}/merge_requests/{$iid}");
    $author = (string) ($mr['author']['username'] ?? '');
    $url = (string) ($mr['web_url'] ?? '');
    $title = (string) ($mr['title'] ?? '');
    $head = ["MR !{$iid}：{$title}", "作者：@{$author}", $url, ''];

    $review = approvalNeedsChangedPaths($rules) ? reviewPaths($rules, mergeBase($sha, $mr), $sha) : ['paths' => ['*'], 'cosmetic' => []];
    printCosmetic($review['cosmetic']);
    $domains = approvalTouchedDomains($rules, $review['paths']);
    if ($domains === []) {
        echo "MR !{$iid} 沒有動到需要核准的路徑，略過合併後檢查。\n";

        return 0;
    }

    // 沒有合併時間就無法排除「合併後才補的核准」，不能宣告有效
    $mergedAt = $mr['merged_at'] ?? null;
    if (!is_string($mergedAt) || $mergedAt === '') {
        return sendAuditAlert(['⚠️ 合併後檢查無法確認合併時間，請人工確認這張 MR 合併前有沒有人核准', ...$head]);
    }

    $notes = gitlabGet("/projects/{$project}/merge_requests/{$iid}/notes", ['sort' => 'asc', 'order_by' => 'created_at'], true);
    $result = approvalEvaluate($notes, $author, approvalReviewerUnion($domains), (string) ($mr['created_at'] ?? ''), $mergedAt);

    if ($result['approved']) {
        echo "✔ MR !{$iid} 合併時有有效核准（" . implode('、', $result['approvers']) . "）。\n";

        return 0;
    }

    if (approvalHotfixBypass($mr)) {
        return sendAuditAlert([
            '⚠️ hotfix 分支緊急放行的 MR 已合併，請在 1 個工作天內補審',
            ...$head,
            "來源分支：{$mr['source_branch']}",
            '可以補審的人：',
            ...approvalWhoToAsk($domains, $author),
        ]);
    }

    return sendAuditAlert([
        '🚨 MR 在沒有有效核准的情況下合併了',
        ...$head,
        '常見原因：核准檢查通過後，審核人取消核准，但沒有把 MR 改回 Draft。',
        '請以下任一位補審，有問題就開修正 MR：',
        ...approvalWhoToAsk($domains, $author),
    ]);
}

function runSync(array $rules): int
{
    // 正式與試行兩條規則要共用同一份清單，否則只比對到第一條
    $yml = (string) @file_get_contents(CI_PATHS_FILE);
    if (substr_count($yml, 'changes: &approval_paths') !== 1 || substr_count($yml, 'changes: *approval_paths') !== 1) {
        echo "✘ .gitlab-ci/approval/approval.yml 的兩條規則要用同一份路徑清單：一條寫 changes: &approval_paths、另一條寫 changes: *approval_paths。\n";

        return 1;
    }
    $ci = approvalReadCiPaths(CI_PATHS_FILE, '# approval-paths:begin', '# approval-paths:end');
    $diff = approvalCompareCiPaths($rules, $ci);
    if ($diff['missing_in_ci'] === [] && $diff['extra_in_ci'] === []) {
        echo '✔ 名單與 CI 設定的路徑一致（' . count($ci) . " 條）。\n";

        return 0;
    }

    echo "✘ 名單（.gitlab/approval-rules.json）與 CI 設定（.gitlab-ci/approval/approval.yml）的路徑不一致。\n";
    echo "  兩邊要一起改：目錄寫法在名單是「目錄/」，在 CI 是「目錄/」後面再接 ** 與 /*。\n";
    foreach ($diff['missing_in_ci'] as $path) {
        echo "  ・CI 設定少了：{$path}\n";
    }
    foreach ($diff['extra_in_ci'] as $path) {
        echo "  ・CI 設定多了：{$path}\n";
    }

    return 1;
}

/**
 * 合併後檢查的比對起點：淺層 clone 先補抓合併後的 commit，起點怎麼算見共用的 gitMergedFrom()。
 */
function mergeBase(string $sha, array $mr): string
{
    ensureCommit($sha);

    return gitMergedFrom($sha, $mr);
}

/** 專案 CI 變數 APPROVAL_ENFORCE 等於 "true" 才是正式模式；其他值或沒設都是試行模式。 */
function isEnforced(): bool
{
    return getenv('APPROVAL_ENFORCE') === 'true';
}

/**
 * 發 Slack 到 SLACK_CHANNEL_ID；試行模式或本機試跑（APPROVAL_DRY_RUN=1）只印出來，發送程式見共用的 gateSendSlack()。
 */
function sendAuditAlert(array $lines): int
{
    return gateSendSlack($lines, 'SLACK_CHANNEL_ID', isEnforced() && getenv('APPROVAL_DRY_RUN') !== '1');
}

$mode = $argv[1] ?? '';
try {
    $rules = approvalLoadRules(RULES_FILE);
    exit(match ($mode) {
        'check' => runCheck($rules),
        'audit' => runAudit($rules),
        'sync' => runSync($rules),
        default => throw new RuntimeException('用法：php .gitlab-ci/approval/approval-check.php check|audit|sync'),
    });
} catch (Throwable $e) {
    echo '✘ ' . $e->getMessage() . "\n";
    // 合併後檢查失敗時沒人會去看預設分支的 pipeline，改發 Slack 才會被注意到
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
