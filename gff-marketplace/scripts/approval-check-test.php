<?php

declare(strict_types=1);

/**
 * scripts/lib/approval.php 的判定測試，由 validate.sh 呼叫。
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

$created   = '2026-10-01T01:00:00.000Z';
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
$approve   = note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED) + ['id' => 10];
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

// 19. 失效的核准：提醒重新核准的 job 靠 stale 與 since_by 決定要 @ 誰、怎麼說明
$push = "added 1 commit\n\n<ul><li>abc - fix</li></ul>";
$r = approvalEvaluate([
    note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED),
    note('rb', '2026-10-01T03:00:00.000Z', $push),
], 'rb', $reviewers, $created);
expect('核准後別人推 commit，列為失效並記下推的人', !$r['approved'] && $r['stale'] === ['toro'] && $r['since_by'] === 'rb');
expect('失效核准帶上原本的核准時間', $r['stale_at'] === ['toro' => '2026-10-01T02:00:00+00:00']);

$r = approvalEvaluate([
    note('rb', '2026-10-01T02:00:00.000Z', $push),
    note('toro', '2026-10-01T03:00:00.000Z', APPROVAL_NOTE_APPROVED),
], 'rb', $reviewers, $created);
expect('推 commit 後才核准，有效且沒有失效的核准', $r['approved'] && $r['stale'] === []);

$r = approvalEvaluate([
    note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED),
    note('rb', '2026-10-01T03:00:00.000Z', $push),
    note('toro', '2026-10-01T04:00:00.000Z', APPROVAL_NOTE_UNAPPROVED),
], 'rb', $reviewers, $created);
expect('失效後審核人已撤銷核准，不再列為失效', $r['stale'] === []);

$r = approvalEvaluate([
    note('rb', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED),
    note('rb', '2026-10-01T03:00:00.000Z', $push),
], 'rb', $reviewers, $created);
expect('作者自己的核准被推送蓋過，不列為失效', $r['stale'] === []);

$r = approvalEvaluate([
    note('Max', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED),
    note('rb', '2026-10-01T03:00:00.000Z', $push),
], 'rb', $reviewers, $created);
expect('名單外的人核准被推送蓋過，不列為失效', $r['stale'] === []);

$r = approvalEvaluate([note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED)], 'rb', $reviewers, $created);
expect('開單後沒推過 commit，沒有失效的核准、也沒有推的人', $r['stale'] === [] && $r['since_by'] === '');

$r = approvalEvaluate([
    note('toro', '2026-10-01T02:00:00.000Z', APPROVAL_NOTE_APPROVED),
    note('rhys', '2026-10-01T02:30:00.000Z', APPROVAL_NOTE_APPROVED),
    note('rb', '2026-10-01T03:00:00.000Z', $push),
    note('Max', '2026-10-01T05:00:00.000Z', 'added 2 commits'),
], 'rb', $reviewers, $created);
expect('多位失效依帳號排序、保留實際大小寫，推的人取最後一次', $r['stale'] === ['rhys', 'toro'] && $r['since_by'] === 'Max');

// 標籤判斷：字串陣列與物件陣列都要認得
expect('標籤是字串陣列', approvalHasLabel(['labels' => ['hotfix', 'breaking']], 'hotfix'));
expect('標籤是物件陣列', approvalHasLabel(['labels' => [['name' => 'hotfix']]], 'hotfix'));
expect('沒有標籤欄位', !approvalHasLabel([], 'hotfix'));

// 需不需要取變更檔案：全是 "*" 不用；有任何具體路徑就要
$whole = ['bypass_label' => 'hotfix', 'domains' => [['name' => 'a', 'paths' => ['*'], 'reviewers' => ['x', 'y']]]];
$mixed = ['bypass_label' => 'hotfix', 'domains' => [...$whole['domains'], ['name' => 'b', 'paths' => ['app/'], 'reviewers' => ['x', 'y']]]];
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

// 實際名單檔可以正常讀取，而且分級如預期：只有會執行或影響安裝的 plugin 改動、breaking 標籤要核准
try {
    $rules = approvalLoadRules(__DIR__ . '/../.gitlab/approval-rules.json');
    expect('.gitlab/approval-rules.json 格式正確', $rules['domains'] !== []);

    $skillText = ['plugins/dev-toolkit/skills/git-commit/SKILL.md', 'docs/skill-cheatsheet.html', 'README.md'];
    expect('只動 skill 內文與文件，不需要核准', approvalTouchedDomains($rules, $skillText) === []);
    $repoOnly = ['scripts/validate.sh', '.gitlab-ci.yml', '.gitlab/approval-rules.json', 'CONTRIBUTING.md'];
    expect('只動 CI、核准機制與規則文件，不需要核准', approvalTouchedDomains($rules, $repoOnly) === []);
    expect('每個 plugin 的 bin 目錄都比對得到', approvalTouchedDomains($rules, ['plugins/gitlab-mcp/bin/gitlab-mcp.js']) !== []);
    expect('改團隊名單需要核准', approvalTouchedDomains($rules, ['team-roster.yaml']) !== []);
    expect('只動 skill 內文但貼了 breaking，需要核准', approvalTouchedDomains($rules, $skillText, ['breaking']) !== []);
} catch (Throwable $e) {
    expect('.gitlab/approval-rules.json 格式正確：' . $e->getMessage(), false);
}

exit($failures === 0 ? 0 : 1);
