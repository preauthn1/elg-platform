<?php

declare(strict_types=1);

/**
 * MR 合併關卡共用的 Slack 發送：合併後檢查發現問題時通知用。
 * 機器人權杖一律讀專案 CI 變數 SLACK_BOT_TOKEN；發到哪個頻道由呼叫端傳入頻道 id 所在的 CI 變數名稱，
 * 每道檢查各自決定（核准檢查用 SLACK_CHANNEL_ID，PM 確認檢查用 SLACK_REVIEW_CHANNEL_ID）。
 */

/**
 * 發 Slack；$live 為 false（試行模式、本機試跑），或沒設定權杖、頻道時只印在 job log。
 * 試行期大家還沒開始照規則補，發了只是洗版。
 * 回傳 0：沒送 Slack 時不擋預設分支的 pipeline（job 本身也設 allow_failure）；Slack 回失敗時回傳 1。
 *
 * @param  list<string>  $lines  訊息，每個元素一行
 * @param  string  $channelVar  存頻道 id 的 CI 變數名稱
 */
function gateSendSlack(array $lines, string $channelVar, bool $live): int
{
    $text = implode("\n", $lines);
    $token = [REDACTED] getenv('SLACK_BOT_TOKEN');
    $channel = (string) getenv($channelVar);

    if (!$live || $token === '' || $channel === '') {
        echo "--- 未送 Slack（試行模式、試跑或未設定 SLACK_BOT_TOKEN / {$channelVar}）---\n{$text}\n--- END ---\n";

        return 0;
    }

    $ch = curl_init('https://example.invalid/api/chat.postMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['channel' => $channel, 'text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Content-Type: application/json; charset=utf-8'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = (string) curl_exec($ch);
    // Slack 即使 HTTP 200 也可能回 {"ok":false}，要看 ok 欄位
    if ((json_decode($resp, true)['ok'] ?? false) !== true) {
        echo 'Slack 送出失敗：' . (curl_error($ch) ?: $resp) . "\n{$text}\n";

        return 1;
    }
    echo "已送出 Slack 警告：\n{$text}\n";

    return 0;
}
