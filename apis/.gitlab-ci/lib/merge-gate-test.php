<?php

declare(strict_types=1);

/**
 * merge-gate.php 的判定測試，由 CI job「核准名單檢查」呼叫；本機可直接 php 執行。
 * 只守 gateLastPushAt()；豁免、收集工單、名單格式由 pm-confirm-check-test.php 守。
 * 留言格式照 GitLab notes API：推 commit＝"added 1 commit\n\n<ul>…"，system=true。
 */

require __DIR__ . '/merge-gate.php';

$failures = 0;
function expect(string $label, bool $ok): void
{
    global $failures;
    printf("  %s %s\n", $ok ? '✔' : '✘', $label);
    if (!$ok) {
        $failures++;
    }
}

function note(string $at, string $body, bool $system = true): array
{
    return ['author' => ['username' => 'rb'], 'created_at' => $at, 'body' => $body, 'system' => $system];
}

$created = '2026-10-01T01:00:00.000Z';
$notes = [
    note('2026-10-01T03:00:00.000Z', "added 2 commits\n\n<ul><li>abc</li></ul>"),
    note('2026-10-01T02:00:00.000Z', 'added 1 commit'),
    note('2026-10-01T04:00:00.000Z', 'approved this merge request'),
    note('2026-10-01T05:00:00.000Z', 'added 1 commit', false),
];

// 1. 取系統留言裡最晚的一次推 commit，不看留言順序、核准留言與一般留言；沒推過就是 MR 建立時間
expect(
    '最後一次推 commit 取最晚的系統推送留言，沒推過就用 MR 建立時間',
    gateLastPushAt($notes, $created) == new DateTimeImmutable('2026-10-01T03:00:00.000Z')
    && gateLastPushAt([], $created) == new DateTimeImmutable($created),
);

// 2. 合併後檢查只看合併時間以前的推送
expect(
    '傳了截止時間就不看之後的推送',
    gateLastPushAt($notes, $created, '2026-10-01T02:30:00.000Z') == new DateTimeImmutable('2026-10-01T02:00:00.000Z'),
);

exit($failures === 0 ? 0 : 1);
