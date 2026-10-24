<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\SexySlotCq9;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Console\Command;

/**
 * 把 Newman（Postman collection run）匯出的 HTML 報告轉成精簡的 Markdown：
 * Summary 統計＋每筆失敗斷言的標題／Assertion，方便直接讀不用開 4MB+ 的 HTML。
 */
class ParseNewmanReportCommand extends Command
{
    protected $signature = 'report:parse-newman
        {file : Newman HTML 報告檔名或路徑（相對路徑會找 Source/ 底下的檔案）}
        {--output= : 輸出 Markdown 路徑（預設 Output/report.md）}';

    protected $description = '解析 Newman HTML 報告為 Markdown（Summary 統計＋失敗斷言清單）';

    public function handle(): int
    {
        $inputPath = $this->resolvePath($this->argument('file'), 'Source');

        if (!is_file($inputPath)) {
            $this->error("找不到檔案：{$inputPath}");

            return self::FAILURE;
        }

        $outputPath = $this->option('output')
            ? $this->resolvePath($this->option('output'))
            : __DIR__ . '/Output/report.md';

        $html = file_get_contents($inputPath);

        libxml_use_internal_errors(true);

        $dom = new DOMDocument;
        $dom->loadHTML($html);

        $xpath = new DOMXPath($dom);

        $output = [];

        $output[] = '# Newman Test Report';
        $output[] = '';

        $this->appendSummary($xpath, $output);
        $this->appendFailedTests($xpath, $output);

        file_put_contents($outputPath, implode(PHP_EOL, $output));

        $this->info("已輸出：{$outputPath}");

        return self::SUCCESS;
    }

    /**
     * 相對路徑一律視為 command 同目錄下 $subdir 子目錄的檔案（預設輸入放 Source/、輸出放 Output/）
     */
    private function resolvePath(string $path, string $subdir = ''): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return __DIR__ . ($subdir !== '' ? "/{$subdir}" : '') . '/' . $path;
    }

    private function appendSummary(DOMXPath $xpath, array &$output): void
    {
        $summaryRows = $xpath->query('//table//tbody/tr');

        if ($summaryRows === false || $summaryRows->length === 0) {
            return;
        }

        $output[] = '## Summary';
        $output[] = '';

        foreach ($summaryRows as $row) {
            if (!$row instanceof DOMElement) {
                continue;
            }

            $cols = $row->getElementsByTagName('td');

            if ($cols->length < 3) {
                continue;
            }

            $item = trim($cols->item(0)->textContent);
            $total = trim($cols->item(1)->textContent);
            $failed = trim($cols->item(2)->textContent);

            $output[] = "- {$item}: {$total} (Failed: {$failed})";
        }

        $output[] = '';
    }

    private function appendFailedTests(DOMXPath $xpath, array &$output): void
    {
        $output[] = '## Failed Tests';
        $output[] = '';

        $cards = $xpath->query("//div[contains(@class,'card')][contains(@class,'border-danger')]");

        foreach ($cards as $card) {
            $titleNode = $xpath->query(".//div[contains(@class,'card-header')]//a", $card)->item(0);

            if (!$titleNode) {
                continue;
            }

            $title = trim(html_entity_decode($titleNode->textContent));

            $failedNode = $xpath->query(".//h5[strong[contains(text(),'Failed Test')]]", $card)->item(0);
            $failed = $failedNode ? trim(str_replace('Failed Test:', '', $failedNode->textContent)) : '';

            $codeNode = $xpath->query('.//pre/code', $card)->item(0);
            $assertion = $codeNode ? trim($codeNode->textContent) : '';

            $output[] = "### {$title}";
            $output[] = '';

            if ($failed !== '') {
                $output[] = '**Failed Test**';
                $output[] = $failed;
                $output[] = '';
            }

            if ($assertion !== '') {
                $output[] = '**Assertion**';
                $output[] = '```';
                $output[] = $assertion;
                $output[] = '```';
                $output[] = '';
            }

            $output[] = '---';
            $output[] = '';
        }
    }
}
