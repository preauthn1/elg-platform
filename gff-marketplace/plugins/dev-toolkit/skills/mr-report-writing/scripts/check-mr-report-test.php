<?php

declare(strict_types=1);

/**
 * scripts/check-mr-report.php 的夾具測試，由 validate.sh 呼叫。
 *
 * 夾具命名規則（scripts/fixtures/*.md）：
 * - `pass-*.md` 是完整、格式正確的 MR description，`check_mr_report()` 必須回傳空陣列。
 * - `fail-*.md` 第一行是 `<!-- expect: <關鍵字> -->`，本測試會把這一行拿掉再交給
 *   `check_mr_report()`，驗證回傳的錯誤訊息裡至少有一條含那個關鍵字。
 *   「關鍵字」只斷言訊息有沒有講到重點，不要求逐字比對整句錯誤訊息。
 */

require __DIR__ . '/check-mr-report.php';

$failures = 0;

function expect(string $label, bool $ok): void
{
    global $failures;
    printf("  %s %s\n", $ok ? '✔' : '✘', $label);
    if (!$ok) {
        $failures++;
    }
}

$fixtureDir = __DIR__ . '/fixtures';
$fixtures = glob($fixtureDir . '/*.md') ?: [];
sort($fixtures);

if ($fixtures === []) {
    expect('fixtures 目錄至少要有一份夾具', false);
}

foreach ($fixtures as $path) {
    $name = basename($path);
    $text = (string) file_get_contents($path);

    if (str_starts_with($name, 'pass-')) {
        $errors = check_mr_report($text);
        $ok = $errors === [];
        expect($name, $ok);
        if (!$ok) {
            foreach ($errors as $error) {
                printf("      - %s\n", $error);
            }
        }
        continue;
    }

    if (str_starts_with($name, 'fail-')) {
        $lines = explode("\n", $text);
        $firstLine = $lines[0] ?? '';

        if (!preg_match('/^<!-- expect: (.+?) -->$/', $firstLine, $m)) {
            expect($name . '：第一行要是 `<!-- expect: <關鍵字> -->`', false);
            continue;
        }

        $keyword = $m[1];
        $rest = implode("\n", array_slice($lines, 1));

        $errors = check_mr_report($rest);
        $matched = false;
        foreach ($errors as $error) {
            if (str_contains($error, $keyword)) {
                $matched = true;
                break;
            }
        }

        $ok = $errors !== [] && $matched;
        expect($name . '（expect: ' . $keyword . '）', $ok);
        if (!$ok) {
            if ($errors === []) {
                printf("      - 沒有任何錯誤訊息\n");
            } else {
                foreach ($errors as $error) {
                    printf("      - %s\n", $error);
                }
            }
        }
        continue;
    }

    expect($name . '：夾具檔名要以 pass- 或 fail- 開頭', false);
}

exit($failures === 0 ? 0 : 1);
