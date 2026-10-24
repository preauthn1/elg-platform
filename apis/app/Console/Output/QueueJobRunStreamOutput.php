<?php

declare(strict_types=1);

namespace App\Console\Output;

use App\Models\QueueJobRun;
use Symfony\Component\Console\Output\Output;

/**
 * 邊跑邊寫的 artisan 輸出：累積全文供收尾（fetch()），並每隔 flushSeconds 把目前內容
 * 寫進 queue_job_runs.output（{@see QueueJobRun::streamOutput()}，只留尾段），
 * 後台操作頁輪詢時就看得到長時指令的即時進度，不必等跑完。
 *
 * 給 Artisan::call() 的第三個參數用；RunAdminOperationJob 專用。
 */
class QueueJobRunStreamOutput extends Output
{
    private string $buffer = '';

    private float $lastFlushAt = 0.0;

    public function __construct(
        private readonly ?int $runId,
        private readonly float $flushSeconds = 5.0,
    ) {
        parent::__construct();
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->buffer .= $message . ($newline ? PHP_EOL : '');

        if ($this->runId !== null && microtime(true) - $this->lastFlushAt >= $this->flushSeconds) {
            $this->flush();
        }
    }

    /** 完整輸出（不截斷），供收尾寫入 */
    public function fetch(): string
    {
        return $this->buffer;
    }

    private function flush(): void
    {
        $this->lastFlushAt = microtime(true);
        QueueJobRun::streamOutput($this->runId, $this->buffer);
    }
}
