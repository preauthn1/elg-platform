<?php

declare(strict_types=1);

/**
 * lib/qa-verify.php 的判定測試，本機可直接 php 執行。
 * 只守核心判定：測試組在最後一次推 commit 之後關閉才通過、關閉的人或時間不對就不通過、沒有測試子單直接通過、
 * 查詢失敗不通過、hotfix 分支免檢。commit 類型與線上問題單的豁免沿用 PM 確認檢查的既有測試。
 * 狀態異動紀錄照 GitLab resource_state_events API 的欄位：state（closed／reopened）、user.id、user.username、created_at（UTC）。
 */

require __DIR__ . '/lib/qa-verify.php';

$failures = 0;
function expect(string $label, bool $ok): void
{
    global $failures;
    printf("  %s %s\n", $ok ? '✔' : '✘', $label);
    if (!$ok) {
        $failures++;
    }
}

function stateEvent(string $state, int $userId, string $user, string $at): array
{
    return ['state' => $state, 'user' => ['id' => $userId, 'username' => $user], 'created_at' => $at];
}

function child(int $iid, string $title, string $state = 'CLOSED'): array
{
    return ['reference' => "target/ticket_tasks#{$iid}", 'title' => $title, 'state' => $state];
}

$qaIds = [87, 79];
$pushAt = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-19T02:00:00.000Z');
$children = [
    child(1824, '新增登入功能 - 開發'),
    child(1825, '新增登入功能 - 測試'),
    child(1826, '新增登入功能 - APIs測試'),
];
/** @param array<string, list<array<string, mixed>>> $byRef */
$eventsFrom = static fn (array $byRef): Closure => static fn (string $ref): array => $byRef[$ref] ?? [];

// 1. 全部測試子單都由測試組在最後推 commit 之後關閉，通過；開發子單不看
$r = qaVerifyJudgeMainTicket(4519, $children, $eventsFrom([
    'target/ticket_tasks#1825' => [stateEvent('closed', 87, 'Benwu', '2026-10-19T03:00:00.000Z')],
    'target/ticket_tasks#1826' => [
        stateEvent('closed', 79, 'zoe', '2026-10-18T03:00:00.000Z'),
        stateEvent('reopened', 77, 'rb', '2026-10-19T01:00:00.000Z'),
        stateEvent('closed', 79, 'zoe', '2026-10-19T04:30:00.000Z'),
    ],
]), $pushAt, $qaIds);
expect(
    '測試組在最後推 commit 之後關閉全部測試子單，通過',
    $r['ok'] && $r['lines'] === [
        '子單 target/ticket_tasks#1825「新增登入功能 - 測試」：通過（Benwu 於 2026-10-19 11:00 台北時間關閉）',
        '子單 target/ticket_tasks#1826「新增登入功能 - APIs測試」：通過（zoe 於 2026-10-19 12:30 台北時間關閉）',
    ],
);

// 2. 不在測試組的人關閉，不通過
$r = qaVerifyJudgeMainTicket(4519, [child(1825, '新增登入功能 - 測試')], $eventsFrom([
    'target/ticket_tasks#1825' => [stateEvent('closed', 7, 'Frank', '2026-10-19T03:00:00.000Z')],
]), $pushAt, $qaIds);
expect(
    '不在測試組的人關閉，不通過',
    !$r['ok'] && $r['lines'] === ['子單 target/ticket_tasks#1825「新增登入功能 - 測試」：由 Frank 於 2026-10-19 11:00 台北時間關閉，不在測試名單'],
);

// 3. 在最後推 commit 之前關閉，不通過（QA 測的不是最新的程式）
$r = qaVerifyJudgeMainTicket(4519, [child(1825, '新增登入功能 - 測試')], $eventsFrom([
    'target/ticket_tasks#1825' => [stateEvent('closed', 87, 'Benwu', '2026-10-19T01:59:59.000Z')],
]), $pushAt, $qaIds);
expect(
    '在最後推 commit 之前關閉，不通過',
    !$r['ok'] && $r['lines'] === ['子單 target/ticket_tasks#1825「新增登入功能 - 測試」：Benwu 於 2026-10-19 09:59 台北時間關閉，沒有晚於 MR 最後一次推 commit（2026-10-19 10:00 台北時間），要重新驗收'],
);

// 4. 沒有測試子單，通過；也不去讀狀態異動紀錄與推 commit 時間
$touched = false;
$r = qaVerifyJudgeMainTicket(
    4519,
    [child(1824, '新增登入功能 - 開發', 'OPEN')],
    static function () use (&$touched): array {
        $touched = true;

        return [];
    },
    static function () use (&$touched): DateTimeImmutable {
        $touched = true;

        return new DateTimeImmutable;
    },
    $qaIds,
);
expect(
    '沒有測試子單，通過',
    $r['ok'] && !$touched && $r['lines'] === ['主單 target/tickets#4519：沒有測試子單，不需要 QA 驗收'],
);

// 5. 查詢失敗，不通過（讀不到狀態異動紀錄不能當成通過）
$r = qaVerifyJudgeMainTicket(4519, [child(1825, '新增登入功能 - 測試')], static function (): array {
    throw new RuntimeException('GitLab API 失敗（HTTP 500）');
}, $pushAt, $qaIds);
expect(
    '查詢失敗，不通過',
    !$r['ok'] && $r['lines'] === ['子單 target/ticket_tasks#1825「新增登入功能 - 測試」：查詢失敗（GitLab API 失敗（HTTP 500））'],
);

// 6. hotfix 分支免檢
expect('hotfix 分支免檢', gateExemptReason('hotfix/fix-login', ['feat: 新增登入功能']) === 'hotfix 分支');

exit($failures === 0 ? 0 : 1);
