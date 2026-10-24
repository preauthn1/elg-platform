<?php

declare(strict_types=1);

/**
 * MR 合併關卡共用的 git 操作：執行 git、列出 MR 要合進去的 commit（MR pipeline 用）、
 * 列出已合併的 MR 帶進預設分支的 commit（合併後檢查用）。
 * 失敗一律丟例外，不讓「取不到 commit／變更檔案」被當成「沒有 commit／沒動到」而放行。
 */

/** 執行 git 並回傳 stdout；失敗就丟例外。 */
function gitRun(array $args): string
{
    $cmd = 'git ' . implode(' ', array_map('escapeshellarg', $args));
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

/**
 * 這張 MR 要合進去的 commit，每顆附標題與內文。
 * 豁免判斷扣掉合併用的 commit（$withMerges = false）；收集需求單要含合併用的 commit，
 * 因為 GitLab 的合併訊息會帶 MR 標題，工單號可能只寫在那裡。
 * 呼叫的 job 要用完整歷史（GIT_DEPTH 0）並先 fetch 目標分支；這裡仍先確認找得到共同祖先，
 * 找不到時 origin/<目標>..HEAD 會把目標分支的舊 commit 也算進來，所以直接丟例外。
 *
 * @return list<array{subject: string, body: string}>
 */
function mrCommits(string $target, bool $withMerges): array
{
    $base = "origin/{$target}";
    gitRun(['merge-base', $base, 'HEAD']);

    return gitCommitsInRange("{$base}..HEAD", $withMerges);
}

/**
 * git log 範圍內的 commit，每顆附標題與內文；$withMerges = false 時扣掉合併用的 commit。
 *
 * @return list<array{subject: string, body: string}>
 */
function gitCommitsInRange(string $range, bool $withMerges): array
{
    // 欄位以 0x1f 分隔、每顆 commit 以 0x1e 結尾，commit 內文有換行也拆得開
    $args = ['log', '--format=%s%x1f%b%x1e', $range];
    if (!$withMerges) {
        array_splice($args, 1, 0, '--no-merges');
    }
    $raw = gitRun($args);
    $commits = [];
    foreach (explode("\x1e", $raw) as $chunk) {
        $chunk = ltrim($chunk, "\n");
        if ($chunk === '') {
            continue;
        }
        [$subject, $body] = array_pad(explode("\x1f", $chunk, 2), 2, '');
        $commits[] = ['subject' => $subject, 'body' => $body];
    }

    return $commits;
}

/**
 * 合併後檢查的比對起點：從這個起點到合併後的 commit，就是這張 MR 帶進預設分支的內容。
 * - 合併 commit（兩個 parent）：第一個 parent 是合併前的預設分支，回傳 <sha>^1。
 * - fast-forward 或 squash（一個 parent）：用 MR 的起點 diff_refs.base_sha。只看上一個 commit 的話，
 *   多個 commit 直接快轉進來時會漏掉前面幾個 commit 的變更。
 * 呼叫端要先確認 $sha 在本機的 git 歷史裡。
 */
function gitMergedFrom(string $sha, array $mr): string
{
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

/**
 * 已合併的 MR 帶進預設分支的 commit，每顆附標題與內文（合併後檢查用）。
 * 範圍是 gitMergedFrom() 的起點到合併後的 commit：合併 commit 時是 MR 自己的 commit 加上合併 commit；
 * fast-forward 時是快轉進來的 commit；squash 時只剩壓成一顆的 commit（MR 自己的那幾顆 commit 不在預設分支上）。
 * 起點不在合併後 commit 的歷史裡（範圍會混進預設分支原有的 commit）、或範圍內沒有任何 commit，都丟例外。
 * 呼叫的 job 要用完整歷史（GIT_DEPTH 0）。
 *
 * @return list<array{subject: string, body: string}>
 */
function mergedMrCommits(string $sha, array $mr, bool $withMerges): array
{
    try {
        gitRun(['rev-parse', '--quiet', '--verify', $sha . '^{commit}']);
    } catch (RuntimeException) {
        throw new RuntimeException("CI 的 git 歷史裡找不到 {$sha}，無法列出這張 MR 合進來的 commit");
    }
    $from = gitMergedFrom($sha, $mr);
    try {
        gitRun(['merge-base', '--is-ancestor', $from, $sha]);
    } catch (RuntimeException) {
        throw new RuntimeException("MR 的起點 {$from} 不在 {$sha} 的歷史裡，無法列出這張 MR 合進來的 commit");
    }
    $commits = gitCommitsInRange("{$from}..{$sha}", $withMerges);
    if ($commits === [] && $withMerges) {
        throw new RuntimeException("{$from}..{$sha} 之間沒有任何 commit，無法列出這張 MR 合進來的 commit");
    }

    return $commits;
}
