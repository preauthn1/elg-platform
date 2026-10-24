<?php

declare(strict_types=1);

namespace App\Console\Commands\GoogleCloud;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * 所有透過 gcloud CLI 操作 GCP 的 Artisan 命令基礎類別。
 *
 * 提供：
 * - gcloud 安裝檢查
 * - Cloud Logging 查詢（回傳已解析的 log entry 陣列）
 */
abstract class GcpCommand extends Command
{
    /**
     * 確認 gcloud CLI 已安裝且可執行。
     */
    protected function checkGcloud(): bool
    {
        $process = new Process(['which', 'gcloud']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * 執行 `gcloud logging read` 並回傳已解析的 log entry 陣列。
     *
     * @param  string  $filter  Cloud Logging 過濾條件
     * @param  string  $project  GCP project ID
     * @param  int  $limit  最多回傳筆數；0 表示不限制（省略 --limit 旗標）
     * @param  int  $timeout  gcloud 指令 timeout 秒數（預設 120）
     * @return array log entry 陣列；查詢失敗時回傳空陣列
     */
    protected function readGcpLogs(
        string $filter,
        string $project,
        int $limit,
        int $timeout = 120,
    ): array {
        $args = [
            'gcloud', 'logging', 'read',
            $filter,
            '--project=' . $project,
            '--format=json',
        ];

        if ($limit > 0) {
            $args[] = '--limit=' . $limit;
        }

        $process = new Process($args);

        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->warn('gcloud 查詢失敗：' . trim($process->getErrorOutput()));

            return [];
        }

        $entries = json_decode($process->getOutput(), true);

        return is_array($entries) ? $entries : [];
    }
}
