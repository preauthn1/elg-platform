<?php

declare(strict_types=1);

/**
 * lib/approval.php 的判定測試，由 CI job「核准名單檢查」呼叫；本機可直接 php 執行。
 * 留言格式照 GitLab 實際回傳：按核准＝"approved this merge request"、取消＝"unapproved this merge request"、
 * 推 commit＝"added 1 commit\n\n<ul>…"，都是 system=true。
 */

require __DIR__ . '/lib/approval.php';

$failures = 0;
function expect(string $label, bool $ok): void
{
    global $failures;
    printf("  %s %s\n", $ok ? '✔' : '✘', $label);
    if (!$ok) {
        $failures++;
    }
}

function note(string $user, string $at, string $body, bool $system = true): array
{
    return ['author' => ['username' => $user], 'created_at' => $at, 'body' => $body, 'system' => $system];
}

$created = '2026-10-01T01:00:00.000Z';
$reviewers = ['rb', 'toro', 'Rhys'];

// 1. 審核人核准 → 通過
$r = approvalEvaluate([note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED)], 'rb', $reviewers, $created);
expect('審核人核准就通過', $r['approved'] && $r['approvers'] === ['toro']);

// 2. 作者自己核准不算
$r = approvalEvaluate([note('rb', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED)], 'rb', $reviewers, $created);
expect('作者自己核准不算', !$r['approved']);

// 3. 名單外的人核准不算
$r = approvalEvaluate([note('Max', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED)], 'rb', $reviewers, $created);
expect('名單外的人核准不算', !$r['approved']);

// 4. 核准後又推 commit → 舊核准失效
$r = approvalEvaluate([
    note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED),
    note('rb', '2026-10-01T03:00:00.000Z', "added 1 commit\n\n<ul><li>abc - fix</li></ul>"),
], 'rb', $reviewers, $created);
expect('核准後又推 commit，舊核准失效', !$r['approved']);

// 5. 推 commit 後重新核准 → 通過
$r = approvalEvaluate([
    note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED),
    note('rb', '2026-10-01T03:00:00.000Z', 'added 2 commits'),
    note('toro', '2026-10-01T04:00:00.000Z', APPROVAL_NOTE_APPROVED),
], 'rb', $reviewers, $created);
expect('推 commit 後重新核准就通過', $r['approved']);

// 6. 核准後取消 → 不算
$r = approvalEvaluate([
    note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED),
    note('toro', '2026-10-01T02:05:00.000Z', APPROVAL_NOTE_UNAPPROVED),
], 'rb', $reviewers, $created);
expect('核准後又取消，不算', !$r['approved']);

// 7. 帳號大小寫不同也算同一人（名單寫 Rhys、GitLab 回 rhys）
$r = approvalEvaluate([note('rhys', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED)], 'rb', $reviewers, $created);
expect('帳號比對不分大小寫', $r['approved']);

// 8. 一般留言照抄核准文字不算（system=false）
$r = approvalEvaluate([note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED, false)], 'rb', $reviewers, $created);
expect('一般留言寫同樣文字不算核准', !$r['approved']);

// 9. 合併後檢查只看合併前的動作：合併後才核准不算
$r = approvalEvaluate(
    [note('toro', '2026-10-01T05:00:00.000Z', APPROVAL_NOTE_APPROVED)],
    'rb', $reviewers, $created, '2026-10-01T04:00:00.000Z',
);
expect('合併之後才按的核准不算', !$r['approved']);

// 10. 合併後才取消核准，不影響合併當下的判定
$r = approvalEvaluate([
    note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED),
    note('toro', '2026-10-01T05:00:00.000Z', APPROVAL_NOTE_UNAPPROVED),
], 'rb', $reviewers, $created, '2026-10-01T04:00:00.000Z');
expect('合併之後才取消核准，合併當下仍算有核准', $r['approved']);

// 11. 核准時間剛好等於推 commit 的時間：無法判斷先後，從嚴不算
$r = approvalEvaluate([
    note('toro', '2026-10-01T03:00:00.000Z', APPROVAL_NOTE_APPROVED),
    note('rb', '2026-10-01T03:00:00.000Z', 'added 1 commit'),
], 'rb', $reviewers, $created);
expect('核准與推 commit 同一時間，從嚴不算', !$r['approved']);

// 12. 同一時間戳的核准／取消，依留言 ID 決定先後，不依 API 回傳順序
$approve = note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED) + ['id' => 10];
$unapprove = note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_UNAPPROVED) + ['id' => 11];
$a = approvalEvaluate([$approve, $unapprove], 'rb', $reviewers, $created);
$b = approvalEvaluate([$unapprove, $approve], 'rb', $reviewers, $created);
expect('同時間戳依留言 ID 判斷，回傳順序不影響結果', !$a['approved'] && !$b['approved']);

// 13. 取消後又重新核准 → 通過
$r = approvalEvaluate([
    note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED),
    note('toro', '2026-10-01T02:05:00.000Z', APPROVAL_NOTE_UNAPPROVED),
    note('toro', '2026-10-01T02:10:00.000Z', APPROVAL_NOTE_APPROVED),
], 'rb', $reviewers, $created);
expect('取消後重新核准就通過', $r['approved']);

// 14. 作者帳號大小寫不同也要排除（作者 RB、核准者 rb）
$r = approvalEvaluate([note('rb', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED)], 'RB', $reviewers, $created);
expect('作者大小寫不同仍排除自己的核准', !$r['approved']);

// 15. MR 缺作者時直接失敗，不能讓作者的核准被當成別人的
try {
    approvalEvaluate([note('rb', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED)], '', $reviewers, $created);
    expect('缺作者時丟例外', false);
} catch (RuntimeException) {
    expect('缺作者時丟例外', true);
}

// 16. 系統留言缺時間時直接失敗
try {
    approvalEvaluate([['author' => ['username' => 'toro'], 'body' => APPROVAL_NOTE_APPROVED, 'system' => true]], 'rb', $reviewers, $created);
    expect('系統留言缺時間時丟例外', false);
} catch (RuntimeException) {
    expect('系統留言缺時間時丟例外', true);
}

// 17. 空留言 → 不通過
expect('沒有任何留言就不通過', !approvalEvaluate([], 'rb', $reviewers, $created)['approved']);

// 18. 開單後沒再推過 commit 時，不能提示「核准因推 commit 失效」
$r = approvalEvaluate([note('rb', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED)], 'rb', $reviewers, $created);
expect('開單後沒推過 commit，不標示核准被推送重置', $r['reset_by_push'] === false);
$r = approvalEvaluate([note('rb', '2026-10-01T03:00:00.000Z', 'added 1 commit')], 'rb', $reviewers, $created);
expect('開單後推過 commit，標示核准被推送重置', $r['reset_by_push'] === true);

// 緊急放行只看來源分支，不看標籤
expect('hotfix/ 分支沒核准也放行', approvalHotfixBypass(['source_branch' => 'hotfix/fix-login', 'labels' => []]));
expect('feature/ 分支貼了 hotfix 標籤仍不放行', !approvalHotfixBypass(['source_branch' => 'feature/4137-login', 'labels' => ['hotfix']]));

// 需不需要取變更檔案：全是 "*" 不用；有任何具體路徑就要
$whole = ['domains' => [['name' => 'a', 'paths' => ['*'], 'reviewers' => ['x', 'y']]]];
$mixed = ['domains' => [...$whole['domains'], ['name' => 'b', 'paths' => ['app/'], 'reviewers' => ['x', 'y']]]];
expect('只有整個 repo 的領域時不取變更檔案', !approvalNeedsChangedPaths($whole));
expect('有具體路徑的領域時要取變更檔案', approvalNeedsChangedPaths($mixed));

// 路徑比對
expect('目錄前綴比對', approvalPathMatches('app/Services/Payment/', 'app/Services/Payment/X/Driver.php'));
expect('目錄前綴不誤中相似名稱', !approvalPathMatches('app/Services/Payment/', 'app/Services/PaymentLog.php'));
expect('萬用字元比對', approvalPathMatches('app/Models/EventV2*.php', 'app/Models/EventV2Detail.php'));
expect('"*" 涵蓋整個 repo', approvalPathMatches('*', 'README.md'));

// 找人清單排除作者、第一位標建議
$lines = approvalWhoToAsk([['name' => '整個 repo', 'reviewers' => ['rb', 'toro', 'Rhys']]], 'RB');
expect('找人清單排除作者並標出建議先找', $lines === ['  ・整個 repo → toro（建議先找）、Rhys']);

// 名單路徑換成 CI rules: changes 的寫法
expect('目錄補上 **/*', approvalCiChangesPattern('app/Vendors/') === 'app/Vendors/**/*');
expect('檔名照原樣', approvalCiChangesPattern('config/filament.php') === 'config/filament.php');
try {
    approvalCiChangesPattern('*');
    expect('整個 repo 不能換成 rules: changes', false);
} catch (RuntimeException) {
    expect('整個 repo 不能換成 rules: changes', true);
}

// 名單與 CI 路徑比對：兩邊一致才通過，差異分兩邊列出
$pair = ['domains' => [['name' => 'a', 'paths' => ['app/Vendors/', 'config/x.php'], 'reviewers' => ['x', 'y']]]];
$same = approvalCompareCiPaths($pair, ['app/Vendors/**/*', 'config/x.php']);
expect('名單與 CI 路徑一致', $same['missing_in_ci'] === [] && $same['extra_in_ci'] === []);
$diff = approvalCompareCiPaths($pair, ['app/Vendors/**/*', 'config/y.php']);
expect('名單與 CI 路徑不一致時列出兩邊差異', $diff['missing_in_ci'] === ['config/x.php'] && $diff['extra_in_ci'] === ['config/y.php']);

// 讀 CI 設定的路徑清單：只讀標記之間、引號可有可無；缺標記就丟例外
$tmp = tempnam(sys_get_temp_dir(), 'approval');
file_put_contents($tmp, "x:\n  - \"not/this\"\n  # begin\n  # 註解\n  - \"a/**/*\"\n  - b.php\n  # end\n  - c.php\n");
expect('只讀標記之間的路徑', approvalReadCiPaths($tmp, '# begin', '# end') === ['a/**/*', 'b.php']);
try {
    approvalReadCiPaths($tmp, '# nope', '# end');
    expect('缺標記時丟例外', false);
} catch (RuntimeException) {
    expect('缺標記時丟例外', true);
}
file_put_contents($tmp, "  # begin\n  - \"a/**/*\" # 行尾註解\n  # end\n");
try {
    approvalReadCiPaths($tmp, '# begin', '# end');
    expect('路徑後面接行尾註解時丟例外', false);
} catch (RuntimeException) {
    expect('路徑後面接行尾註解時丟例外', true);
}
unlink($tmp);

// 萬用字元不跨目錄（與 CI 的 rules: changes 一致）
expect('萬用字元不跨目錄', !approvalPathMatches('app/Models/EventV2*.php', 'app/Models/EventV2/Detail.php'));

// APIs 名單的路徑比對：該中的要中，名稱相近的不能誤中
try {
    $apis = approvalLoadRules(__DIR__ . '/../../.gitlab/approval-rules.json');
    $hit = static fn (string $path): array => array_column(approvalTouchedDomains($apis, [$path]), 'name');
    expect('金流服務目錄', $hit('app/Services/Payment/Providers/SurePay/Driver.php') === ['金流']);
    expect('代付服務不誤中名稱相近的檔案', $hit('app/Services/WithdrawalPayoutServiceTest.php') === []);
    expect('會員提款服務', $hit('app/Services/WithdrawalService.php') === ['會員提款與風控']);
    expect('活動 V2 Model 萬用字元', $hit('app/Models/EventV2Detail.php') === ['活動系統']);
    expect('前台活動 Controller 萬用字元', $hit('app/Http/Controllers/Frontend/EventLuckyDrawController.php') === ['活動系統']);
    expect('app/Enums/Event/ 不誤中同一層名稱相近的檔案', $hit('app/Enums/EventTypeEnum.php') === []);
    expect('單一錢包回調屬於遊戲對接', $hit('app/Http/Controllers/External/SingleWallets/PPSWController.php') === ['遊戲對接']);
    expect('migration 屬於資料表結構', $hit('database/migrations/2026_10_01_000000_add_x.php') === ['資料表結構']);
    expect('改審核名單要經過 CI 設定的審核人', $hit('.gitlab/approval-rules.json') === ['CI 設定']);
    expect('改檢查程式要經過 CI 設定的審核人', $hit('.gitlab-ci/approval/lib/approval.php') === ['CI 設定']);
    expect('一般程式不需要核准', $hit('app/Services/MemberService.php') === []);
} catch (Throwable $e) {
    expect('APIs 名單路徑比對：' . $e->getMessage(), false);
}

// PHP 只改註解或排版就視為邏輯沒變
$base = "<?php\nfunction fee(\$a) { return \$a * 0.02; }\n";
expect('加註解與重新排版，邏輯沒變', approvalPhpLogicUnchanged($base, "<?php\n\n/** 手續費 */\nfunction fee( \$a )\n{\n    return \$a * 0.02; // 備註\n}\n"));
expect('改數字就算有改', !approvalPhpLogicUnchanged($base, "<?php\nfunction fee(\$a) { return \$a * 0.03; }\n"));
expect('把程式註解掉就算有改', !approvalPhpLogicUnchanged("<?php\nfoo();\nbar();\n", "<?php\nfoo();\n// bar();\n"));
expect('加上 strict_types 就算有改', !approvalPhpLogicUnchanged("<?php\nfoo();\n", "<?php\n\ndeclare(strict_types=1);\n\nfoo();\n"));
expect('不含跳脫字元的單雙引號互換視為沒改', approvalPhpLogicUnchanged("<?php\necho \"abc\";\n", "<?php\necho 'abc';\n"));
expect('含跳脫字元的引號互換算有改', !approvalPhpLogicUnchanged("<?php\necho \"a\\n\";\n", "<?php\necho 'a\\n';\n"));
expect('字串內容不同算有改', !approvalPhpLogicUnchanged("<?php\necho 'abc';\n", "<?php\necho 'abd';\n"));
expect('語法錯誤時從嚴當成有改', !approvalPhpLogicUnchanged("<?php\nfoo(\n", "<?php\nfoo(\n"));

// 實際名單檔可以正常讀取
try {
    $rules = approvalLoadRules(__DIR__ . '/../../.gitlab/approval-rules.json');
    expect('.gitlab/approval-rules.json 格式正確', $rules['domains'] !== []);
} catch (Throwable $e) {
    expect('.gitlab/approval-rules.json 格式正確：' . $e->getMessage(), false);
}

exit($failures === 0 ? 0 : 1);
