<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Output;

use App\Console\Output\QueueJobRunStreamOutput;
use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Models\QueueJobRun;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Console/Output/QueueJobRunStreamOutputTest.php --compact
 */
class QueueJobRunStreamOutputTest extends TestCase
{
    private function pendingRunId(): int
    {
        return QueueJobRun::createPending(QueueJobKeyEnum::ADMIN_OPERATION, 'stream-test', '2026-09-14 00:00:00', QueueJobRunSourceEnum::MANUAL);
    }

    #[TestDox('每次 write 到達 flush 間隔即回寫執行列 output，fetch 仍回完整內容')]
    public function test_writes_stream_to_queue_job_run_and_fetch_returns_all(): void
    {
        $runId = $this->pendingRunId();
        $output = new QueueJobRunStreamOutput($runId, flushSeconds: 0);

        $output->writeln('進度: 1/3');
        $this->assertSame("進度: 1/3\n", QueueJobRun::find($runId)->output);

        $output->writeln('進度: 2/3');
        $this->assertSame("進度: 1/3\n進度: 2/3\n", QueueJobRun::find($runId)->output);
        $this->assertSame("進度: 1/3\n進度: 2/3\n", $output->fetch());
    }

    #[TestDox('即時回寫超過 100KB 只留尾段並標註，收尾 finishRun 才寫全文')]
    public function test_stream_keeps_only_tail_when_oversized(): void
    {
        $runId = $this->pendingRunId();

        QueueJobRun::streamOutput($runId, str_repeat('頭', 40_000) . str_repeat('尾', 40_000));

        $stored = QueueJobRun::find($runId)->output;
        $this->assertStringStartsWith('…（執行中，僅顯示最後 100KB', $stored);
        $this->assertStringEndsWith('尾', $stored);
        $this->assertLessThanOrEqual(100_200, strlen($stored));
    }

    #[TestDox('已收尾的列不被即時回寫覆蓋')]
    public function test_stream_does_not_overwrite_finished_run(): void
    {
        $runId = $this->pendingRunId();
        QueueJobRun::finishRun($runId, success: true, output: 'final');

        QueueJobRun::streamOutput($runId, 'late progress');

        $this->assertSame('final', QueueJobRun::find($runId)->output);
    }
}
