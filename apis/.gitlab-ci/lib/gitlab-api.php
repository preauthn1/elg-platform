<?php

declare(strict_types=1);

/**
 * MR 合併關卡共用的 GitLab API 呼叫：REST、GraphQL、子任務找主單、讀團隊名單、確認線上問題單存在。
 * HTTP 錯誤一律丟例外：查不到資料時寧可讓檢查失敗，也不要誤判成通過。
 *
 * 權杖由呼叫端決定：REST 的 $auth 傳整行 header，讀別的專案用 gitlabPrivateAuth(權杖)、
 * 讀本專案用 gitlabJobAuth()（CI_JOB_TOKEN）；其餘函式的 $token 傳權杖本身。
 * 每道檢查用哪個 CI 變數當權杖、缺了怎麼提示，寫在各自的入口程式。
 *
 * 入口程式要定義常數 GATE_CHECK_NAME（例如 'PM 確認檢查'），requireEnv() 找不到 CI 變數時用它寫出是哪道檢查。
 * 不在 MR pipeline 執行的模式（例如合併後檢查）另外定義常數 GATE_ENV_HINT，整句取代「<檢查名稱>只能在 MR pipeline 執行」。
 */

require_once __DIR__ . '/merge-gate.php';

/** GitLab 回 404：查無此單時要和其他失敗分開處理（線上問題單查不到算不通過，不算檢查本身壞掉）。 */
final class GitlabNotFound extends RuntimeException {}

/**
 * 呼叫 GitLab REST API，paginate 時自動翻頁直到拿完。
 *
 * @param  string  $auth  整行認證 header，用 gitlabPrivateAuth() 或 gitlabJobAuth() 產生
 * @return array<mixed>
 */
function gitlabGet(string $path, string $auth, array $query = [], bool $paginate = false): array
{
    $all = [];
    $page = 1;

    do {
        $q = $paginate ? $query + ['per_page' => 100, 'page' => $page] : $query;
        $url = gitlabApiBase() . $path . ($q !== [] ? '?' . http_build_query($q) : '');
        [$code, $body] = httpRequest($url, [$auth]);
        if ($code === 404) {
            throw new GitlabNotFound("GitLab API 查無資料（{$path}，HTTP 404）");
        }
        if ($code >= 400 || $code === 0) {
            throw new RuntimeException("GitLab API 失敗（{$path}，HTTP {$code}）：" . mb_substr($body, 0, 300));
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

/** 讀別的專案用的認證 header（個人或機器人的 read_api 權杖）。 */
function gitlabPrivateAuth(string $token): string
{
    return 'PRIVATE-TOKEN: ' . $token;
}

/** 讀本專案用的認證 header（CI_JOB_TOKEN）。 */
function gitlabJobAuth(): string
{
    return 'JOB-TOKEN: ' . requireEnv('CI_JOB_TOKEN');
}

/**
 * 呼叫 GitLab GraphQL。REST 讀不到跨專案的父子關係（子任務在 target/ticket_tasks、主單在 target/tickets），
 * 所以子任務找主單走這裡。回應帶 errors 時丟例外。
 *
 * @return array<mixed>
 */
function gitlabGraphql(string $query, array $variables, string $token): array
{
    $url = preg_replace('#/api/v4$#', '', gitlabApiBase()) . '/api/graphql';
    [$code, $body] = httpRequest(
        $url,
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        (string) json_encode(['query' => $query, 'variables' => $variables], JSON_UNESCAPED_UNICODE),
    );
    if ($code >= 400 || $code === 0) {
        throw new RuntimeException("GitLab GraphQL 失敗（HTTP {$code}）：" . mb_substr($body, 0, 300));
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('GitLab GraphQL 回傳不是 JSON');
    }
    if (!empty($data['errors'])) {
        throw new RuntimeException('GitLab GraphQL 錯誤：' . implode('；', array_column($data['errors'], 'message')));
    }

    return (array) ($data['data'] ?? []);
}

/** @return array{0: int, 1: string} HTTP 狀態碼（連線失敗為 0）與回應內容 */
function httpRequest(string $url, array $headers, ?string $postBody = null): array
{
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15];
    if ($postBody !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $postBody;
    }
    curl_setopt_array($ch, $opts);
    $body = (string) curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    return [$err !== '' ? 0 : $code, $err !== '' ? $err : $body];
}

function gitlabApiBase(): string
{
    return rtrim(requireEnv('CI_API_V4_URL'), '/');
}

function requireEnv(string $name): string
{
    $value = (string) getenv($name);
    if ($value === '') {
        $hint = defined('GATE_ENV_HINT') ? (string) constant('GATE_ENV_HINT') : GATE_CHECK_NAME . '只能在 MR pipeline 執行';
        throw new RuntimeException("找不到 CI 變數 {$name}，{$hint}");
    }

    return $value;
}

/**
 * 每一步包一層：失敗時把步驟名稱接在原因前面，報告裡看得出卡在哪一步。
 *
 * @template T
 *
 * @param  callable(): T  $fn
 * @return T
 */
function step(string $name, callable $fn): mixed
{
    try {
        return $fn();
    } catch (GitlabNotFound $e) {
        throw new GitlabNotFound("{$name}失敗：" . $e->getMessage(), 0, $e);
    } catch (Throwable $e) {
        throw new RuntimeException("{$name}失敗：" . $e->getMessage(), 0, $e);
    }
}

/**
 * 子任務的主單。parent 不在 target/tickets（或沒有 parent）時回傳 null。
 * GraphQL 寫法與 gitlab-mcp plugin 的 list_child_items 相同（namespace → workItem → 階層 widget 的 parent）。
 */
function parentTicketOf(int $taskIid, string $token): ?int
{
    $query = 'query($fullPath: ID!, $iid: String!) {
  namespace(fullPath: $fullPath) {
    workItem(iid: $iid) {
      widgets { type ... on WorkItemWidgetHierarchy { parent { reference(full: true) } } }
    }
  }
}';
    $data = gitlabGraphql($query, ['fullPath' => TICKET_TASKS_PROJECT, 'iid' => (string) $taskIid], $token);
    $workItem = $data['namespace']['workItem'] ?? null;
    if (!is_array($workItem)) {
        throw new RuntimeException('查不到這張子任務');
    }
    foreach ((array) ($workItem['widgets'] ?? []) as $widget) {
        if (($widget['type'] ?? '') !== 'HIERARCHY') {
            continue;
        }
        $reference = (string) ($widget['parent']['reference'] ?? '');
        if (preg_match('/^' . preg_quote(TICKETS_PROJECT, '/') . '#(\d+)$/', $reference, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    return null;
}

/**
 * 讀 GFF-Marketplace main 上的團隊名單。回傳解析後的成員與名單版本（最後修改這份檔案的 commit）。
 * 要哪一組的人由呼叫端用 gateRosterGroupUserIds() 取。
 *
 * @return array{members: list<array{username: string, user_id: int, groups: list<string>}>, commit: string}
 */
function loadTeamRoster(string $token): array
{
    $file = gitlabGet(
        '/projects/' . rawurlencode(ROSTER_PROJECT) . '/repository/files/' . rawurlencode(ROSTER_FILE),
        gitlabPrivateAuth($token),
        ['ref' => ROSTER_REF],
    );
    $content = base64_decode((string) ($file['content'] ?? ''), true);
    if ($content === false || $content === '') {
        throw new RuntimeException('團隊名單內容是空的或無法解碼');
    }

    return [
        'members' => gateParseRoster($content),
        'commit' => (string) ($file['last_commit_id'] ?? ''),
    ];
}

/**
 * 只附線上問題單的 MR：逐張確認單存在，回傳查不到的那幾張（每張一行報告）。全部查得到時回傳空陣列。
 * 先查單存不存在，是防止隨手寫一個單號就跳過檢查。404 以外的失敗照常丟例外，算檢查本身失敗。
 *
 * @param  list<int>  $opIids
 * @param  string  $stepLabel  報告裡的步驟編號，例如「第 2 步」
 * @return list<string>
 */
function missingOpTickets(array $opIids, string $token, string $stepLabel): array
{
    $ops = rawurlencode(OP_TICKETS_PROJECT);
    $missing = [];
    foreach ($opIids as $opIid) {
        try {
            step("{$stepLabel}（查線上問題單 " . OP_TICKETS_PROJECT . "#{$opIid}）", static fn (): array => gitlabGet("/projects/{$ops}/issues/{$opIid}", gitlabPrivateAuth($token)));
        } catch (GitlabNotFound) {
            $missing[] = '線上問題單 ' . OP_TICKETS_PROJECT . "#{$opIid}：不存在";
        }
    }

    return $missing;
}
