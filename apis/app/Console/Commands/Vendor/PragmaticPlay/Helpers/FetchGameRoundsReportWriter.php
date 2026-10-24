<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay\Helpers;

use Exception;
use Illuminate\Support\Carbon;

final class FetchGameRoundsReportWriter
{
    private string $path;

    /** @var resource */
    private $handle;

    private bool $headerWritten = false;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? storage_path('reports/ppsw_game_rounds_urls_' . Carbon::now()->format('YmdHis') . '.csv');

        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $handle = @fopen($this->path, 'wb');
        if ($handle === false) {
            throw new Exception('Unable to open report file: ' . $this->path);
        }

        $this->handle = $handle;

        // 寫入 UTF-8 BOM（讓 Excel 正確識別編碼）
        fwrite($this->handle, "\xEF\xBB\xBF");
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * 寫入 CSV 標題行（包含 metadata）
     */
    public function writeHeader(string $title, array $context = []): void
    {
        // 寫入 metadata 作為註解行（# 開頭）
        $metaLines = [];
        $metaLines[] = ['# ' . $title];
        $metaLines[] = ['# generated_at', Carbon::now()->toDateTimeString()];
        foreach ($context as $k => $v) {
            $metaLines[] = ['# ' . $k, is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)];
        }
        $metaLines[] = []; // 空行

        foreach ($metaLines as $line) {
            $this->writeCsvRow($line);
        }

        // 寫入 CSV 欄位標題
        $this->writeCsvRow(['domain', 'data_type', 'timepoint', 'url']);
        $this->headerWritten = true;
    }

    /**
     * 追加 URL 記錄到 CSV（每個 URL 一行）
     *
     * @param  list<string>  $urls
     */
    public function appendUrls(string $domain, string $dataType, array $urls): void
    {
        foreach ($urls as $url) {
            // 從 URL 中提取 timepoint
            $timepoint = $this->extractTimepointFromUrl($url);

            $this->writeCsvRow([
                $domain,
                $dataType,
                $timepoint,
                $url,
            ]);
        }
    }

    /**
     * 從 URL 中提取 timepoint 參數
     */
    private function extractTimepointFromUrl(string $url): string
    {
        if (preg_match('/timepoint=([^&]+)/', $url, $matches)) {
            return urldecode($matches[1]);
        }

        return '';
    }

    /**
     * 寫入一行 CSV
     */
    private function writeCsvRow(array $fields): void
    {
        $result = fputcsv($this->handle, $fields);
        if ($result === false) {
            throw new Exception('Unable to write CSV row to report file: ' . $this->path);
        }

        @fflush($this->handle);
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            @fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
