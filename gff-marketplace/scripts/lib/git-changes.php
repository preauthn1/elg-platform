<?php

declare(strict_types=1);

/**
 * 用 git 算出變更檔案的共用函式，供 approval-check.php（MR 核准檢查）與 notify-merge.php（合併通知）使用。
 *
 * 不向 GitLab 取 MR 的變更檔案清單：CI_JOB_TOKEN 讀不到那些介面（實打 diffs、changes、compare、檔案樹都回 404）。
 * 也不另外放一把專案存取權杖去讀：權杖會到期，一到期每張 MR 的核准檢查都會失敗，專案還要多保管一個密鑰。
 * 也不用 .gitlab-ci.yml 的 rules:changes 讓 GitLab 判斷有沒有動到路徑：路徑清單要在 CI 設定與
 *   .gitlab/approval-rules.json 各寫一份，而且 job 只知道「有動到」，分不出是哪個領域。
 * CI 的映像檔沒有 git，由 .gitlab-ci.yml 在 job 開始時安裝。
 * 這些函式會執行 git，所以不放在只放純函式的 scripts/lib/approval.php。
 */

/**
 * 執行 git 並回傳 stdout；失敗就丟例外，由呼叫端決定怎麼處理，不讓「取不到變更檔案」被當成「沒有變更」。
 */
function gitRun(array $args): string
{
    $cmd  = 'git ' . implode(' ', array_map('escapeshellarg', $args));
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException("無法執行 {$cmd}");
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException("{$cmd} 失敗：" . trim($err));
    }

    return $out;
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

function gitAvailable(): bool
{
    try {
        gitRun(['--version']);

        return true;
    } catch (RuntimeException) {
        return false;
    }
}

/**
 * CI 是淺層 clone，需要的 commit 不在本機時補抓：完整 SHA 直接抓那個 commit，
 * 其他寫法（例如 <sha>^1）加深歷史後再找。補完仍找不到就丟例外。
 * 環境裡沒有 git 時另外報錯，不讓人誤以為是 commit 不存在。
 */
function ensureCommit(string $rev): void
{
    if (gitHasCommit($rev)) {
        return;
    }
    if (!gitAvailable()) {
        throw new RuntimeException('這個環境沒有 git 指令，無法算出變更檔案');
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
 * 用 git 算出兩個 commit 之間的變更檔案路徑。
 * --no-renames：搬移檔案時新舊路徑都要列出。只列新路徑的話，把檔案搬出需要核准的目錄就不必核准，
 *   被搬走的 skill 也不會出現在合併通知裡。
 * -z：檔名原樣輸出；不加的話，中文檔名會被 git 加上引號與跳脫字元，比對不到任何路徑規則。
 *
 * @return list<string>
 */
function changedPaths(string $from, string $to): array
{
    if ($from === '' || $to === '') {
        throw new RuntimeException('缺少比對起點或終點，無法算出變更檔案');
    }
    ensureCommit($from);
    ensureCommit($to);
    $out = rtrim(gitRun(['diff', '--no-renames', '--name-only', '-z', $from, $to]), "\0");

    return $out === '' ? [] : explode("\0", $out);
}

/**
 * 合併進 main 之後，要算「這次合併帶進來的變更」時的比對起點（合併後檢查與合併通知共用）。
 * - 合併 commit（兩個 parent）：第一個 parent 是合併前的 main，兩者的差異就是這張 MR 帶進來的變更。
 * - fast-forward 或 squash（一個 parent）：用 MR 的起點 diff_refs.base_sha。只看上一個 commit 的話，
 *   多個 commit 直接快轉進來時會漏掉前面幾個 commit 的變更。
 */
function mergeBase(string $sha, array $mr): string
{
    ensureCommit($sha);
    $parents = preg_split('/\s+/', trim(gitRun(['rev-list', '--parents', '-n', '1', $sha])));
    if (count($parents) > 2) {
        return $sha . '^1';
    }
    $base = (string) ($mr['diff_refs']['base_sha'] ?? '');
    if ($base === '') {
        throw new RuntimeException('MR 缺少 diff_refs.base_sha，無法算出 fast-forward 合併帶進來的變更');
    }

    return $base;
}
