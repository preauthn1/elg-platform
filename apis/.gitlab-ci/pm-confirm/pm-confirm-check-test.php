<?php

declare(strict_types=1);

/**
 * lib/pm-confirm.php 與共用的 .gitlab-ci/lib/merge-gate.php 的判定測試，本機可直接 php 執行。
 * 只守核心判定：豁免、收集工單、最後一次加標籤的人、PM 名單比對與名單格式，以及合併後檢查看的是合併當下的標籤。
 * 標籤異動紀錄照 GitLab resource_label_events API 的欄位：action（add／remove）、label.name、user.id、created_at（UTC）。
 */

require __DIR__ . '/lib/pm-confirm.php';

$failures = 0;
function expect(string $label, bool $ok): void
{
    global $failures;
    printf("  %s %s\n", $ok ? '✔' : '✘', $label);
    if (!$ok) {
        $failures++;
    }
}

function labelEvent(string $action, int $userId, string $user, string $at, string $label = PM_CONFIRM_LABEL): array
{
    return ['action' => $action, 'user' => ['id' => $userId, 'username' => $user], 'created_at' => $at, 'label' => ['name' => $label]];
}

$pmIds = [7, 66];
$withLabel = ['P1', PM_CONFIRM_LABEL];

// 1. hotfix 分支豁免，不看 commit
expect('hotfix 分支豁免', gateExemptReason('hotfix/fix-login', ['feat: 新增登入功能']) === 'hotfix 分支');

// 2. commit 全是不影響功能的類型才豁免；夾帶一顆 feat 或看不出類型的就不豁免
expect(
    'commit 全是 docs／chore／revert 豁免、夾帶 feat 或看不出類型不豁免',
    gateExemptReason('feature/x', ['docs: 補充說明文件', 'chore(ci): 更新設定', 'Revert "feat: 某功能"']) !== null
    && gateExemptReason('feature/x', ['chore: 更新依賴套件', 'feat: 新增功能']) === null
    && gateExemptReason('feature/x', ['docs: 補充說明文件', '隨手修一下']) === null
    && gateExemptReason('feature/x', []) === null,
);

// 3. 收集工單：認短參照與網址，不認裸 #N 與黏在其他字後面的寫法
$found = gateCollectTickets([
    "feat: 新功能 target/tickets#4519\n\nRefs: http://example.invalid:1688/target/ticket_tasks/-/work_items/1837",
    '工單：target/tickets#4519、#1234、xxelg/tickets#99、https://example.invalid/target/op_tickets/-/issues/3584',
]);
expect(
    '收集工單認得 target/tickets#N、工單網址，不認裸 #N',
    $found === ['tickets' => [4519], 'ticket_tasks' => [1837], 'op_tickets' => [3584]],
);

// 4. 最後一次加標籤的人：被移除後再加回，以最後那次加的人為準
$events = [
    labelEvent('add', 77, 'rb', '2026-10-15T02:00:00.000Z'),
    labelEvent('remove', 7, 'Frank', '2026-10-15T03:00:00.000Z'),
    labelEvent('add', 7, 'Frank', '2026-10-16T06:08:30.000Z'),
    labelEvent('add', 77, 'rb', '2026-10-17T01:00:00.000Z', 'P1'),
];
$r = pmConfirmJudgeMainTicket(4530, $withLabel, $events, $pmIds);
expect(
    '移除後再加回，以最後加的 PM 為準並換成台北時間',
    $r['ok'] && $r['line'] === '主單 target/tickets#4530：通過（Frank 於 2026-10-16 14:08 台北時間加上）',
);

// 5. 同一秒有兩筆這個標籤的異動，先後判斷不出來就不通過
$r = pmConfirmJudgeMainTicket(4530, $withLabel, [
    labelEvent('remove', 77, 'rb', '2026-10-16T06:08:30.100Z'),
    labelEvent('add', 7, 'Frank', '2026-10-16T06:08:30.900Z'),
], $pmIds);
expect('同一秒的標籤異動判斷不出先後，不通過', !$r['ok'] && $r['line'] === '主單 target/tickets#4530：標籤異動先後判斷不出來');

// 6. 最後加標籤的人不在 PM 組就不通過；主單沒有標籤也不通過
$r = pmConfirmJudgeMainTicket(4530, $withLabel, [labelEvent('add', 77, 'rb', '2026-10-16T06:08:30.000Z')], $pmIds);
$noLabel = pmConfirmJudgeMainTicket(4519, ['P1'], [labelEvent('add', 7, 'Frank', '2026-10-16T06:08:30.000Z')], $pmIds);
expect(
    '加標籤的人不在 PM 組、或主單沒有標籤，都不通過',
    !$r['ok'] && $r['line'] === '主單 target/tickets#4530：0. PM確認 是 rb 加的，不在 PM 名單'
    && !$noLabel['ok'] && $noLabel['line'] === '主單 target/tickets#4519：沒有 0. PM確認',
);

// 7. 名單：照固定結構讀得出 PM；格式看不懂或 PM 組是空的都丟例外
$roster = "version: 1\n\nmembers:\n  - username: rb\n    user_id: 77\n    call_names: [rb, RB]\n    groups: [後端]\n"
    . "  - username: Frank\n    user_id: 7\n    groups: [PM]\n\nrole_by_group:\n  PM: PM\n";
$broken = [
    '縮排不對' => str_replace('    user_id: 7', '      user_id: 7', $roster),
    'user_id 不是整數' => str_replace('user_id: 7', 'user_id: seven', $roster),
    'PM 組是空的' => str_replace('groups: [PM]', 'groups: [測試]', $roster),
];
$allThrow = true;
foreach ($broken as $yaml) {
    try {
        pmConfirmPmUserIds(gateParseRoster($yaml));
        $allThrow = false;
    } catch (RuntimeException) {
    }
}
expect('名單讀得出 PM 組，格式看不懂或 PM 組是空的就不通過', pmConfirmPmUserIds(gateParseRoster($roster)) === [7] && $allThrow);

// 8. 合併後檢查：PM 在合併後才補貼標籤，合併當下沒有，不通過（即使主單現在掛著）
$mergedAt = '2026-10-16T06:00:00.000Z';
$r = pmConfirmJudgeAtMerge(4530, [labelEvent('add', 7, 'Frank', '2026-10-16T07:00:00.000Z')], $pmIds, $mergedAt);
expect(
    '合併後才補貼標籤，判定不通過',
    !$r['ok'] && $r['line'] === '主單 target/tickets#4530：合併當下沒有 0. PM確認（合併前沒有加上的異動紀錄）',
);

// 9. 合併後檢查：PM 在合併前加上、合併後才移除，合併當下掛著，通過（即使主單現在沒有）
$r = pmConfirmJudgeAtMerge(4530, [
    labelEvent('add', 7, 'Frank', '2026-10-16T05:00:00.000Z'),
    labelEvent('remove', 7, 'Frank', '2026-10-16T08:00:00.000Z'),
], $pmIds, $mergedAt);
expect(
    '合併後才移除標籤，判定通過',
    $r['ok'] && $r['line'] === '主單 target/tickets#4530：通過（Frank 於 2026-10-16 13:00 台北時間加上）',
);

exit($failures === 0 ? 0 : 1);
