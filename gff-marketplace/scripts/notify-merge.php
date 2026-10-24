<?php

declare(strict_types=1);

/**
 * GitLab CI post-merge Slack notifier.
 *
 * push 進 main 後查 CI_COMMIT_SHA 對應的 MR，依變更性質分流成兩類通知：
 *   1. MR 帶 'breaking' label，或動到 plugin 設定檔（.claude-plugin/marketplace.json、
 *      plugin.json、.mcp.json、bin/）
 *                             → 重大變更通知（更新提醒，動到 MCP 設定時附重啟與 /mcp 檢查）。
 *                               設定檔照 CONTRIBUTING 本來就要貼 breaking，忘了貼也照樣提醒團隊更新；
 *                               改觸發詞、移除 skill 從路徑分不出來，仍靠標籤
 *   2. 否則若動到 plugins/<p>/ 底下的 skills、agents、commands
 *                             → 一般更新通知（輕量，列受影響的 skill、agent、command，不附更新步驟）
 *   3. 兩者皆非               → 不發（只改 plugin 其他檔案、文件或 CI，不打擾團隊）
 *
 * 兩類互斥：重大變更通知本身已含更新步驟，不重複發第二則。
 * 分類依據見 CONTRIBUTING §9（改 skill、agent、command 內容不算 breaking，但團隊不更新就
 * 吃不到新版，故仍需一則輕量通知）。
 *
 * Required CI/CD Variables (Settings → CI/CD → Variables, Protected):
 *   SLACK_BOT_TOKEN   — Bot User OAuth Token (xoxb-...), scope chat:write
 *   SLACK_CHANNEL_ID  — Target channel ID (e.g. C0123456789)
 *
 * 這次合併動到哪些檔案用 git 算（scripts/lib/git-changes.php）。CI 的映像檔沒有 git，
 * 由 .gitlab-ci.yml 在 job 開始時安裝；裝不起來時腳本照樣執行，處理方式見下方取變更檔案的段落。
 */

require __DIR__ . '/lib/git-changes.php';

/** 單一 plugin 的同一類（skill／agent／command）最多列出幾支，超過則收斂成「等 N 支」 */
const ITEM_LIST_LIMIT = 8;

/** 一般更新通知要逐支列名的 plugin 子目錄，key 是目錄名、value 是通知上的類別名 */
const ITEM_KINDS = [
    'skills'   => 'skill',
    'agents'   => 'agent',
    'commands' => 'command',
];

/**
 * breaking 通知附的「取得新版」步驟。一般更新通知不附這段。
 *
 * 只列團隊統一的兩步：開自動更新、在對話框跑 marketplace update。兩步都是 slash command，
 * 沒有作業系統差異。
 * Claude Code 2.1.280 實測 `/plugin marketplace update` 會把已裝的 plugin 一併換版，
 * 不必逐支更新；逐支的 `/plugin update <plugin>@gff-marketplace` 反而只會打開 plugin 選單。
 * 完整說明見 README「更新（marketplace 異動後）」。
 *
 * @return list<string>
 */
function updateStepLines(): array
{
    return [
        "取得新版：",
        "• 已開自動更新：下次啟動 Claude Code 後會在背景更新，照提示輸入 /reload-plugins 套用",
        "• 要馬上更新：在對話框輸入 /plugin marketplace update gff-marketplace，再輸入 /reload-plugins",
        "",
        "還沒開自動更新：/plugin → Marketplaces → gff-marketplace → Enable auto-update（每個人設一次）",
    ];
}

$apiUrl    = (string) getenv('CI_API_V4_URL');
$projectId = (string) getenv('CI_PROJECT_ID');
$sha       = (string) getenv('CI_COMMIT_SHA');
$jobToken  = [REDACTED] getenv('CI_JOB_TOKEN');
$botToken  = [REDACTED] getenv('SLACK_BOT_TOKEN');
$channelId = (string) getenv('SLACK_CHANNEL_ID');

if ($botToken === '' || $channelId === '') {
    echo "SLACK_BOT_TOKEN / SLACK_CHANNEL_ID 未設定，略過通知。\n";
    exit(0);
}

// 查詢此 commit 關聯的 MR
$ch = curl_init("{$apiUrl}/projects/{$projectId}/repository/commits/{$sha}/merge_requests");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["JOB-TOKEN: [REDACTED]"],
    CURLOPT_TIMEOUT        => 10,
]);
$mrs  = json_decode((string) curl_exec($ch), true) ?? [];
$err  = curl_error($ch);

if ($err !== '') {
    echo "GitLab API curl error: {$err}\n";
    exit(1);
}

// 同一 commit 可能關聯多個 MR（cherry-pick、跨分支引用）。先收斂到「已合併進 main」的那些，
// 避免取到別條分支的 MR 而查錯 diff；若 API 未回 state / target_branch 導致篩空，退回全體以免通知全掛。
$scoped = array_values(array_filter($mrs, static function (array $candidate): bool {
    return ($candidate['state'] ?? '') === 'merged' && ($candidate['target_branch'] ?? '') === 'main';
}));
$pool = $scoped !== [] ? $scoped : $mrs;

// 優先取帶 breaking label 的 MR；沒有則取第一個當一般更新的來源
$breaking = null;
foreach ($pool as $candidate) {
    if (in_array('breaking', $candidate['labels'] ?? [], true)) {
        $breaking = $candidate;
        break;
    }
}
$mr = $breaking ?? ($pool[0] ?? null);

if ($mr === null) {
    echo "此 commit 無關聯 MR，略過通知。\n";
    exit(0);
}

$iid = (int) $mr['iid'];

// 用 git 算出這次合併帶進來的變更檔案，從中取四項資訊：
//   1. $affectedPlugins：本次動到哪些 plugin（path 形如 plugins/<plugin>/…）
//   2. $affectsMcp：是否動到 MCP 類檔案（CONTRIBUTING「Plugin 修改流程」：plugin.json / .mcp.json / bin wrapper）
//   3. $changedItems：每個 plugin 動到哪些 skill、agent、command，決定發不發一般更新通知並逐支列名；
//      plugin 裡不屬於這三類的檔案（bin、hooks、scripts、README 等）不列
//   4. $touchesManifest：是否動到 plugin 設定檔（MCP 類檔案再加上 .claude-plugin/marketplace.json），
//      動到就算沒貼 breaking 也發重大變更通知
// 不向 GitLab 取 MR 的變更檔案：CI_JOB_TOKEN 讀不到那個介面（回 404），見 scripts/lib/git-changes.php 開頭。
// 取不到變更檔案時（例如 job 裡沒有 git）保守處理：
//   貼了 breaking 的重大變更通知照發，$affectsMcp 視為 true（寧可多提醒一次 /mcp），不列受影響的 plugin 名單；
//   $touchesManifest 維持 false，沒貼 breaking 的不會自動升級；
//   非 breaking 無法判斷有沒有動到 skill、agent、command，讓 job 失敗，不當成「沒動到」而略過。
$affectsMcp      = true;
$touchesManifest = false;
$affectedPlugins = [];
$changedItems    = [];
$pathsError      = null;
try {
    $paths      = changedPaths(mergeBase($sha, $mr), $sha);
    $affectsMcp = false;
    foreach ($paths as $path) {
        // 受影響 plugin：取 plugins/<plugin>/ 的第二段（去重用 key）
        if (preg_match('#^plugins/([^/]+)/(.+)$#', $path, $m)) {
            $affectedPlugins[$m[1]] = true;
            // skills/<name>/…、agents/<name>.md、commands/<name>.md 取 <name>
            if (preg_match('#^(skills|agents|commands)/([^/]+?)(?:\.md)?(?:/|$)#', $m[2], $item)) {
                $changedItems[$m[1]][$item[1]][$item[2]] = true;
            }
        }
        $base = basename($path);
        if ($base === '.mcp.json' || $base === 'plugin.json' || str_contains($path, '/bin/')) {
            $affectsMcp      = true;
            $touchesManifest = true;
        }
        if ($path === '.claude-plugin/marketplace.json') {
            $touchesManifest = true;
        }
    }
} catch (RuntimeException $e) {
    $pathsError = $e->getMessage();
    echo "取不到這次合併的變更檔案：{$pathsError}\n";
}
$affectedPlugins = array_keys($affectedPlugins);
sort($affectedPlugins);
ksort($changedItems);

// 取不到變更檔案時 $touchesManifest 維持 false，只看標籤
$isBreaking = $breaking !== null || $touchesManifest;

// 兩種通知都列 MR 標題、作者與連結，讓讀者知道這次為什麼改、要問誰
$title  = (string) $mr['title'];
$url    = (string) $mr['web_url'];
$author = (string) ($mr['author']['username'] ?? '?');
$mrInfo = [
    "MR !{$iid}: {$title}",
    "Author: @{$author}",
    $url,
];

if ($isBreaking) {
    $lines = [
        "GFF-Marketplace 重大變更",
        ...$mrInfo,
        "",
        "請更新到此版本。",
    ];

    if ($breaking === null) {
        $lines[] = "這個 MR 沒貼 breaking 標籤，因為動到 plugin 設定檔，自動當成重大變更。";
    }

    // 算得出變更檔案才列受影響的 plugin；取不到時清單是空的，不列
    if ($affectedPlugins !== []) {
        $lines[] = "本次受影響的 plugin：" . implode('、', $affectedPlugins);
    }

    $lines[] = "";
    array_push($lines, ...updateStepLines());

    if ($affectsMcp) {
        // MCP server 的連線設定變了；重啟比 /reload-plugins 保險，確保各 server 依新設定重連
        $lines[] = "";
        $lines[] = "本次動到 MCP 設定：更新後請重啟 Claude Code，再跑 /mcp 確認所有 MCP server 連線正常；有未上線的就重裝對應 plugin";
    }
} else {
    // 非 breaking：只在動到 skill、agent、command 時發輕量通知（改 plugin 其他檔案、文件或 CI 不打擾團隊）
    if ($pathsError !== null) {
        echo "無法判斷這次合併有沒有動到 skill、agent、command，沒有發出通知。\n";
        exit(1);
    }
    if ($changedItems === []) {
        echo "非 breaking 且未動到 skill、agent、command，略過通知。\n";
        exit(0);
    }

    $lines = [
        "GFF-Marketplace 更新",
        ...$mrInfo,
        "",
    ];

    $lines[] = "本次更新：";
    foreach ($changedItems as $plugin => $kinds) {
        $parts = [];
        foreach (ITEM_KINDS as $dir => $label) {
            if (!isset($kinds[$dir])) {
                continue;
            }
            $names = array_keys($kinds[$dir]);
            sort($names);
            $shown = array_slice($names, 0, ITEM_LIST_LIMIT);
            $rest  = count($names) - count($shown);
            $parts[] = "{$label} " . implode('、', $shown) . ($rest > 0 ? " 等 " . count($names) . " 支" : '');
        }
        $lines[] = "  • {$plugin}：" . implode('；', $parts);
    }
}

$text = implode("\n", $lines);

// 本地驗證訊息格式用：組完訊息即印出並結束，不呼叫 Slack
if (getenv('NOTIFY_DRY_RUN') === '1') {
    echo "--- DRY RUN (" . ($isBreaking ? 'breaking' : 'update') . ") ---\n";
    echo $text . "\n";
    echo "--- END ---\n";
    exit(0);
}

$payload = json_encode([
    'channel' => $channelId,
    'text'    => $text,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ch = curl_init('https://example.invalid/api/chat.postMessage');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        "Authorization: Bearer {$botToken}",
        'Content-Type: application/json; charset=utf-8',
    ],
    CURLOPT_TIMEOUT        => 10,
]);
$resp = (string) curl_exec($ch);
$err  = curl_error($ch);

if ($err !== '') {
    echo "Slack curl error: {$err}\n";
    exit(1);
}

// Slack 即使 HTTP 200 也可能回 {"ok":false,"error":"..."}，須看 ok 欄位
$result = json_decode($resp, true) ?? [];
if (($result['ok'] ?? false) !== true) {
    echo "Slack API error: {$resp}\n";
    exit(1);
}

echo ($isBreaking ? "Breaking" : "Skill 更新") . " 通知已送出。\n";
exit(0);
