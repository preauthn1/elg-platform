<?php

declare(strict_types=1);

/**
 * scripts/lib/mr-intervention.php 的判定測試，由 validate.sh 呼叫。
 * MR 與留言的欄位照 GitLab 單張 MR API 與 notes API 的實際回傳。
 */

require __DIR__ . '/lib/mr-intervention.php';

$failures = 0;
function expect(string $label, bool $ok): void
{
    global $failures;
    printf("  %s %s\n", $ok ? '✔' : '✘', $label);
    if (!$ok) {
        $failures++;
    }
}

function mr(array $override = []): array
{
    return $override + [
        'iid'                   => 12,
        'author'                => ['username' => 'rb'],
        'created_at'            => '2026-10-22T01:00:00.000Z',
        'sha'                   => 'aaaaaaa1111111111111111111111111111111111',
        'draft'                 => false,
        'detailed_merge_status' => 'mergeable',
        'reviewers'             => [['username' => 'toro']],
    ];
}

function note(string $user, string $at, string $body, bool $system = true, int $id = 1): array
{
    return ['id' => $id, 'author' => ['username' => $user], 'created_at' => $at, 'body' => $body, 'system' => $system];
}

$empty    = mrInterventionEmptyState();
$approved = [note('toro', '2026-10-22T02:00:00.000Z', APPROVAL_NOTE_APPROVED)];

// 1. 新指派的審核人才通知；已通知過的、作者自己都不通知；Draft 也發
$r = mrInterventionDetect(
    mr(['draft' => true, 'reviewers' => [['username' => 'toro'], ['username' => 'Rhys'], ['username' => 'rb']]]),
    ['approval_pending' => true, 'stale' => []],
    ['reviewers' => ['toro'], 'stale_sha' => '', 'mergeable_sha' => ''],
);
expect('只通知新加進來的審核人，排除作者，Draft 也發', $r['events'] === [['type' => 'reviewer', 'to' => ['Rhys']]]);
expect('通知後記下帳號', $r['state']['reviewers'] === ['toro', 'rhys']);

// 2. 核准失效：新 commit 通知一次，同一個 commit 不再通知
$approval = mrInterventionApproval(mr(), [...$approved, note('rb', '2026-10-22T03:00:00.000Z', 'added 1 commit', true, 2)], []);
expect('核准後推 commit，指派的審核人列為失效、仍在等核准', $approval === ['approval_pending' => true, 'stale' => ['toro']]);
$r = mrInterventionDetect(mr(), $approval, ['reviewers' => ['toro'], 'stale_sha' => '', 'mergeable_sha' => '']);
expect('核准失效通知該位審核人，不通知可以合併', $r['events'] === [['type' => 'stale', 'to' => ['toro']]]);
$r = mrInterventionDetect(mr(), $approval, $r['state']);
expect('同一個 commit 的核准失效不重發', $r['events'] === []);

// 3. 可以合併：條件都成立才通知作者；Draft 或 GitLab 判定不能合併都不通知
$ok = mrInterventionApproval(mr(), $approved, ['rb', 'toro', 'Rhys']);
$r  = mrInterventionDetect(mr(), $ok, ['reviewers' => ['toro'], 'stale_sha' => '', 'mergeable_sha' => '']);
expect('有效核准且 GitLab 判定可以合併，通知作者', $r['events'] === [['type' => 'mergeable', 'to' => ['rb']]]);
$r = mrInterventionDetect(mr(), $ok, $r['state']);
expect('同一個 commit 的可以合併不重發', $r['events'] === []);
$blocked = [mr(['draft' => true]), mr(['detailed_merge_status' => 'ci_still_running'])];
$hits    = array_filter($blocked, static fn (array $m): bool => mrInterventionDetect($m, $ok, ['reviewers' => ['toro'], 'stale_sha' => '', 'mergeable_sha' => ''])['events'] !== []);
expect('Draft 或 GitLab 判定還不能合併時不通知', $hits === []);

// 4. 指派了兩位、任一位有效核准就算；動到領域但核准的人不在領域名單，仍在等
$two = mr(['reviewers' => [['username' => 'toro'], ['username' => 'Rhys']]]);
expect('指派兩位、一位有效核准就不算在等', !mrInterventionApproval($two, $approved, [])['approval_pending']);
$byMax = [note('Max', '2026-10-22T02:00:00.000Z', APPROVAL_NOTE_APPROVED)];
expect('動到領域、核准的人不在領域名單時仍在等', mrInterventionApproval(mr(['reviewers' => [['username' => 'Max']]]), $byMax, ['rb', 'toro'])['approval_pending']);

// 5. 上線基準：只建立紀錄、不通知
$r = mrInterventionDetect(mr(), $ok, $empty, true);
expect('上線前就開著的 MR 只建立紀錄、不通知', $r['events'] === [] && $r['state']['mergeable_sha'] === mr()['sha'] && $r['state']['reviewers'] === ['toro']);

// 6. 紀錄留言只認機器人自己寫的；內文寫回去讀得回來
$body  = mrInterventionStateBody(['reviewers' => ['toro'], 'stale_sha' => '', 'mergeable_sha' => 'abc']);
$found = mrInterventionFindState([note('rb', '2026-10-22T02:00:00.000Z', $body, false, 5), note('gff-bot', '2026-10-22T02:00:00.000Z', $body, false, 9)], 'gff-bot');
expect('紀錄留言寫回讀得回來，只認機器人自己的', $found !== null && $found['note_id'] === 9 && $found['state']['mergeable_sha'] === 'abc');
expect('紀錄留言內文不 @ 任何人', !str_contains($body, '@'));

// 7. 核准失效留言與「提醒重新核准」共用標記：同一次推送已有人留過就認得出來，換 commit 就不算
$sha    = mr()['sha'];
$staled = [note('gff-bot', '2026-10-22T03:00:00.000Z', "@toro 核准已失效\n\n" . staleMarker($sha), false)];
expect('同一次推送已留過核准失效留言就認得出來，換 commit 不算', staleNoticeExists($staled, $sha) && !staleNoticeExists($staled, 'bbbbbbb'));
$byCheck = [note('gff-bot', '2026-10-22T03:00:00.000Z', "@toro.x 你在 2026-10-22 10:00 的核准，已因 @rb 在 11:00 推的新 commit（aaaaaaa）失效。\n\n" . staleMarker($sha), false)];
$body    = staleNoteBody(['toro', 'toro.x', 'rb'], $byCheck, $sha, true);
expect('已有提醒時只補 @ 沒提醒到的人：推送者與帳號前綴相同的人不算提醒過', $body !== null && str_starts_with($body, '@toro @rb 你的核准') && !str_contains($body, staleMarker($sha)));
$byScan = [note('gff-bot', '2026-10-22T03:00:00.000Z', "@toro @a-b 你的核准已因新 commit（aaaaaaa）失效。\n\n" . staleMarker($sha), false)];
expect('自己留過的核准失效留言 @ 到的人都不再留言', staleNoteBody(['Toro', 'a-b'], $byScan, $sha, false) === null);

// 8. Slack 對照表：帳號不分大小寫，對不到只寫帳號；格式不對要報錯
$map = mrInterventionParseUserMap('{"Toro": "U0BGVBVN4RG"}');
expect('對照表對得到就 @、對不到只寫帳號', mrInterventionSlackName('toro', $map) === '<@U0BGVBVN4RG>' && mrInterventionSlackName('Rhys', $map) === 'Rhys');
try {
    mrInterventionParseUserMap('["U1"]');
    expect('對照表不是 JSON 物件時報錯', false);
} catch (RuntimeException) {
    expect('對照表不是 JSON 物件時報錯', true);
}

exit($failures === 0 ? 0 : 1);
