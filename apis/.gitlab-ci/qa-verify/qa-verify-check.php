<?php

declare(strict_types=1);

/**
 * QA 驗收檢查的 CI 入口：合進預設分支（master）的 MR pipeline，job「QA 驗收檢查」，自動執行。
 *
 *   php .gitlab-ci/qa-verify/qa-verify-check.php
 *
 * 步驟：先看能不能豁免 → 收集需求單 → 子任務換主單 → 讀團隊名單 → 逐張主單列出子單、
 * 判定每張測試子單 → 印出固定格式的報告。判定規則在 lib/qa-verify.php 開頭；
 * 與其他合併關卡共用的判定（豁免、收集工單、名單格式、最後一次推 commit 的時間）在 .gitlab-ci/lib/merge-gate.php，
 * 呼叫 GitLab 的共用程式在 .gitlab-ci/lib/gitlab-api.php，git 操作在 .gitlab-ci/lib/git.php。
 * 任何一步呼叫 GitLab 或 git 失敗都算不通過，並寫出是哪一步，不當成通過。
 *
 * 列子單：GraphQL 讀主單的階層 widget（children），子單在 target/ticket_tasks、主單在 target/tickets，跨專案的父子關係
 *   REST 讀不到。每頁 100 張，照 pageInfo 翻頁到 hasNextPage 為 false，再比對讀到的張數和 count，對不上就算查詢失敗，
 *   不把沒讀完當成沒有測試子單。見 childItemsOf()。
 * 誰在什麼時候關閉：子單的狀態異動紀錄（resource_state_events），每筆有 state、使用者 ID 與時間。
 * 最後一次推 commit 的時間：MR 的系統留言，見 gateLastPushAt()；只有在至少一張測試子單時才讀。
 *
 * 權杖：讀工單與名單用專案 CI 變數 PM_CONFIRM_TOKEN（與 PM 確認檢查共用同一支唯讀 read_api 機器人權杖，見 gitlabToken()），
 *   因為 CI_JOB_TOKEN 讀不到 target/tickets、target/ticket_tasks 的工單、父子關係與狀態異動紀錄。
 *   讀本專案的 MR 描述與留言用 CI_JOB_TOKEN：機器人帳號只加進工單與名單那幾個專案，讀不到本專案。
 *
 * 生效開關：QA_VERIFY_ENFORCE = "true" 是正式模式（CI 設定讓沒通過的 MR 不能合併）；
 *   其他值或沒設是試行模式，只顯示警告。兩種模式都印出完整報告、沒通過都以 1 結束，
 *   差別只在 qa-verify.yml 的 allow_failure。切換只改專案 CI 變數，不必改程式碼。
 *   這裡不直接讀 QA_VERIFY_ENFORCE：qa-verify.yml 的 rules 在建立 pipeline 時把模式寫進
 *   QA_VERIFY_BLOCKING，失敗提示才會跟這條 pipeline 實際擋不擋一致。
 *
 * 用到的 CI 變數：CI_API_V4_URL、CI_PROJECT_ID、CI_JOB_TOKEN、CI_MERGE_REQUEST_IID、
 *   CI_MERGE_REQUEST_TARGET_BRANCH_NAME、CI_MERGE_REQUEST_SOURCE_BRANCH_NAME、PM_CONFIRM_TOKEN、QA_VERIFY_BLOCKING。
 */

require __DIR__ . '/lib/qa-verify.php';
require __DIR__ . '/../lib/gitlab-api.php';
require __DIR__ . '/../lib/git.php';

const GATE_CHECK_NAME = 'QA 驗收檢查';

/**
 * 讀工單與名單用的權杖，與 PM 確認檢查共用 PM_CONFIRM_TOKEN。
 * 機器人帳號刻意不加進本專案；讀本專案的 MR 改用 gitlabJobAuth()（CI_JOB_TOKEN）。
 */
function gitlabToken(): string
{
    $token = [REDACTED] getenv('PM_CONFIRM_TOKEN');
    if ($token === '') {
        throw new RuntimeException(
            '沒有設定專案 CI 變數 PM_CONFIRM_TOKEN（唯讀 read_api 權杖，與 PM 確認檢查共用）。'
            . 'CI_JOB_TOKEN 讀不到 target/tickets、target/ticket_tasks 的工單與狀態異動，請維護者在專案的 Settings → CI/CD → Variables 加上這個變數。',
        );
    }

    return $token;
}

/**
 * 主單底下的全部子單（參照、標題、目前狀態）。GraphQL 寫法與 gitlab-mcp plugin 的 list_child_items 相同
 * （namespace → workItem → 階層 widget 的 children），另外照 pageInfo 翻頁。
 * 讀完後張數和 count 對不上（例如權杖看不到其中幾張）就丟例外：沒讀完不能當成沒有測試子單。
 *
 * @return list<array{reference: string, title: string, state: string}>
 */
function childItemsOf(int $mainIid, string $token): array
{
    $query = 'query($fullPath: ID!, $iid: String!, $after: String) {
  namespace(fullPath: $fullPath) {
    workItem(iid: $iid) {
      widgets {
        type
        ... on WorkItemWidgetHierarchy {
          children(first: 100, after: $after) {
            count
            pageInfo { hasNextPage endCursor }
            nodes { reference(full: true) title state }
          }
        }
      }
    }
  }
}';
    $children = [];
    $count = null;
    $after = null;
    do {
        $data = gitlabGraphql($query, ['fullPath' => TICKETS_PROJECT, 'iid' => (string) $mainIid, 'after' => $after], $token);
        $workItem = $data['namespace']['workItem'] ?? null;
        if (!is_array($workItem)) {
            throw new RuntimeException('查不到這張主單');
        }
        $connection = null;
        foreach ((array) ($workItem['widgets'] ?? []) as $widget) {
            if (($widget['type'] ?? '') === 'HIERARCHY') {
                $connection = $widget['children'] ?? null;
            }
        }
        if (!is_array($connection) || !is_int($connection['count'] ?? null) || !is_array($connection['nodes'] ?? null)) {
            throw new RuntimeException('主單的回應沒有子單清單（階層 widget），無法確認有沒有測試子單');
        }
        $count = $connection['count'];
        foreach ($connection['nodes'] as $node) {
            $reference = $node['reference'] ?? null;
            $title = $node['title'] ?? null;
            $state = $node['state'] ?? null;
            if (!is_string($reference) || !is_string($title) || !is_string($state)) {
                throw new RuntimeException('子單清單缺少參照、標題或狀態');
            }
            $children[] = ['reference' => $reference, 'title' => $title, 'state' => $state];
        }
        $hasNext = ($connection['pageInfo']['hasNextPage'] ?? null);
        $after = $connection['pageInfo']['endCursor'] ?? null;
        if (!is_bool($hasNext) || ($hasNext && !is_string($after))) {
            throw new RuntimeException('子單清單的分頁資訊看不懂，無法確認讀完');
        }
    } while ($hasNext);

    if (count($children) !== $count) {
        throw new RuntimeException('子單清單讀到 ' . count($children) . " 張，但主單顯示有 {$count} 張，沒有讀完");
    }

    return $children;
}

/**
 * 子單的狀態異動紀錄。參照是 <專案路徑>#<單號>（GraphQL reference(full: true) 的寫法）。
 *
 * @return list<array<string, mixed>>
 */
function stateEventsOf(string $reference, string $token): array
{
    if (preg_match('/^(.+)#(\d+)$/', $reference, $m) !== 1) {
        throw new RuntimeException("看不懂子單參照 {$reference}");
    }

    return gitlabGet('/projects/' . rawurlencode($m[1]) . "/issues/{$m[2]}/resource_state_events", gitlabPrivateAuth($token), [], true);
}

/** @return array{0: int, 1: string} 結束碼與報告 */
function runCheck(): array
{
    $iid = requireEnv('CI_MERGE_REQUEST_IID');
    $project = rawurlencode(requireEnv('CI_PROJECT_ID'));
    $target = requireEnv('CI_MERGE_REQUEST_TARGET_BRANCH_NAME');
    $source = requireEnv('CI_MERGE_REQUEST_SOURCE_BRANCH_NAME');
    // 先確認權杖：沒設時連 hotfix 也照樣失敗，設定漏了才看得出來
    $token = [REDACTED];

    // 1. 豁免判斷
    $subjects = gateIsHotfixBranch($source)
        ? []
        : array_column(step('第 1 步（列出 MR 的 commit）', static fn (): array => mrCommits($target, false)), 'subject');
    $exempt = gateExemptReason($source, $subjects);
    if ($exempt !== null) {
        return [0, qaVerifyReport("豁免（{$exempt}）", [], null)];
    }

    // 2. 收集需求單：MR 描述＋每顆 commit 的標題與內文
    $mr = step('第 2 步（讀 MR）', static fn (): array => gitlabGet("/projects/{$project}/merge_requests/{$iid}", gitlabJobAuth()));
    $texts = [(string) ($mr['description'] ?? '')];
    foreach (step('第 2 步（列出 MR 的 commit，含合併用的 commit）', static fn (): array => mrCommits($target, true)) as $commit) {
        $texts[] = $commit['subject'] . "\n" . $commit['body'];
    }
    $found = gateCollectTickets($texts);

    if ($found['tickets'] === [] && $found['ticket_tasks'] === []) {
        if ($found['op_tickets'] === []) {
            return [1, qaVerifyReport('不通過', ['找不到需求單連結'], null)];
        }
        $missing = missingOpTickets($found['op_tickets'], $token, '第 2 步');
        if ($missing !== []) {
            return [1, qaVerifyReport('不通過', $missing, null)];
        }
        $refs = implode('、', array_map(static fn (int $n): string => OP_TICKETS_PROJECT . "#{$n}", $found['op_tickets']));

        return [0, qaVerifyReport("豁免（只附線上問題單 {$refs}）", [], null)];
    }

    // 3. 子任務換主單
    $lines = [];
    $failed = false;
    $mainIids = $found['tickets'];
    foreach ($found['ticket_tasks'] as $taskIid) {
        $reason = '';
        try {
            $parent = parentTicketOf($taskIid, $token);
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
    $roster = step('第 4 步（讀團隊名單 ' . ROSTER_PROJECT . ' ' . ROSTER_REF . ':' . ROSTER_FILE . '）', static fn (): array => loadTeamRoster($token));
    $qaIds = qaVerifyQaUserIds($roster['members']);

    // 最後一次推 commit 的時間：有測試子單時才讀，讀一次給每張子單共用
    $pushAt = null;
    $lastPushAt = static function () use (&$pushAt, $project, $iid, $mr): DateTimeImmutable {
        return $pushAt ??= step('讀 MR 最後一次推 commit 的時間', static fn (): DateTimeImmutable => gateLastPushAt(
            gitlabGet("/projects/{$project}/merge_requests/{$iid}/notes", gitlabJobAuth(), ['sort' => 'asc', 'order_by' => 'created_at'], true),
            (string) ($mr['created_at'] ?? ''),
        ));
    };

    // 5. 逐張主單列出子單，判定每張測試子單
    foreach ($mainIids as $mainIid) {
        $children = step('第 5 步（列出主單 ' . TICKETS_PROJECT . "#{$mainIid} 的子單）", static fn (): array => childItemsOf($mainIid, $token));
        $result = qaVerifyJudgeMainTicket(
            $mainIid,
            $children,
            static fn (string $ref): array => stateEventsOf($ref, $token),
            $lastPushAt,
            $qaIds,
        );
        array_push($lines, ...$result['lines']);
        $failed = $failed || !$result['ok'];
    }

    $pushLine = $pushAt !== null ? gateTaipeiTime($pushAt->format(DATE_RFC3339_EXTENDED)) : null;

    return [$failed ? 1 : 0, qaVerifyReport($failed ? '不通過' : '通過', $lines, $roster['commit'], $pushLine)];
}

// qa-verify.yml 的 rules 在建立 pipeline 時依 QA_VERIFY_ENFORCE 設好這個值，跟 allow_failure 同步
$enforced = getenv('QA_VERIFY_BLOCKING') === 'true';
try {
    [$code, $report] = runCheck();
} catch (Throwable $e) {
    // 主單、子單或名單查不到（404）也走這裡：任何一步失敗都算不通過
    [$code, $report] = [1, qaVerifyReport('不通過', [$e->getMessage()], null)];
}

echo $report;
if ($code !== 0) {
    echo "\n";
    echo $enforced
        ? "沒有通過 QA 驗收檢查，這張 MR 不能合併。\n"
        : "目前是試行期，不會擋合併；正式生效後沒通過就不能合併。\n";
    echo "常見補法：MR 描述補上需求單連結，或請測試組的人在 MR 最後一次推 commit 之後測過、關閉測試子單（推了新 commit 要重開子單、重測後再關）。\n";
    echo "條件補齊後，在 MR 頁面按「Run pipeline」開一條新的 pipeline。\n";
}
exit($code);
