<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Models\CommandLog;
use App\Providers\CommandProvider;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * 排程／手動命令的 exit code 是否正確寫入 command_logs.status
 *
 * php artisan test tests/Unit/Providers/CommandExitCodeStatusTest.php --compact
 *
 * ## 這組測試在守什麼
 * `ScheduleProvider` 與 `CommandProvider` 原本都把 `status` 寫死成 1，導致失敗的命令
 * 在 `command_logs` 讀起來與成功無異（2026-07-29 實測：VWorld 99 萬列、TPower 79 萬列，
 * `status = -1` 皆 0 筆）。修正後兩條路徑都必須依 exit code 判定。
 *
 * ## 為何直接派發事件而不真跑命令
 * 兩條路徑都不可能在測試內自然觸發：
 * - 排程：真跑 `schedule:run` 需排程時間到，且會連帶執行真實命令
 * - 手動：`CommandStarting` / `CommandFinished` 由 `Foundation\Console\Kernel`
 *   的 `rerouteSymfonyCommandEvents()`（標註 `@internal`）掛在 Symfony dispatcher 上，
 *   只在真實 CLI 呼叫時生效，`Artisan::call()` 於測試中**不會**派發
 *
 * 故本檔直接派發事件，參數與順序比照 Laravel 實際行為（排程為 Starting → Finished，
 * 且 Finished 早於 exit code 檢查）。驗的是 listener 的判定邏輯。
 */
class CommandExitCodeStatusTest extends TestCase
{
    private const string PROBE_COMMAND = 'probe:exit-code';

    #[TestDox('手動執行成功的命令記為 status=1')]
    public function test_manual_command_success_is_recorded_as_success(): void
    {
        $log = $this->runManualCommand(exitCode: 0);

        $this->assertSame(1, $log->status);
    }

    #[TestDox('手動執行失敗的命令記為 status=-1')]
    public function test_manual_command_failure_is_recorded_as_failed(): void
    {
        // 修正前這裡恆為 1（硬編值），失敗與成功在 command_logs 完全無法區分
        $log = $this->runManualCommand(exitCode: 1);

        $this->assertSame(-1, $log->status);
    }

    #[TestDox('排程任務成功時記為 status=1')]
    public function test_scheduled_task_success_is_recorded_as_success(): void
    {
        $log = $this->runScheduledTask(exitCode: 0);

        $this->assertSame(1, $log->status);
    }

    #[TestDox('排程任務非零 exit code 記為 status=-1')]
    public function test_scheduled_task_non_zero_exit_code_is_recorded_as_failed(): void
    {
        // 本次修正的核心：Laravel 先派發 Finished 才檢查 exit code，
        // 舊寫法在此寫死 1，使 status=-1 結構上永遠不可達
        $log = $this->runScheduledTask(exitCode: 2);

        $this->assertSame(-1, $log->status);
    }

    #[TestDox('排程任務 exit code 為 null 時維持成功（背景任務）')]
    public function test_scheduled_task_with_null_exit_code_stays_success(): void
    {
        // runInBackground() 的任務在 Finished 當下 exitCode 仍是 null，不可誤判為失敗
        $log = $this->runScheduledTask(exitCode: null);

        $this->assertSame(1, $log->status);
    }

    #[TestDox('Finished 之後補派 Failed 不會覆蓋已寫入的狀態')]
    public function test_late_failed_event_does_not_overwrite_recorded_status(): void
    {
        // Laravel 對失敗命令會「先 Finished 再 Failed」；updateCurrentLog() 收尾把
        // currentLogId 清空，所以第二個事件必然早退。這正是舊實作被卡死的機制，
        // 修正後它反而成為保護：不會重複寫、也不會把已判定的狀態改掉
        $task = $this->makeTask(exitCode: 2);

        Event::dispatch(new ScheduledTaskStarting($task));
        Event::dispatch(new ScheduledTaskFinished($task, 0.5));

        $before = $this->latestProbeLog()->status;

        Event::dispatch(new \Illuminate\Console\Events\ScheduledTaskFailed($task, new \RuntimeException('late')));

        $this->assertSame($before, $this->latestProbeLog()->status);
        $this->assertSame(-1, $before);
    }

    #[TestDox('Horizon 常駐命令不寫入 command_logs')]
    public function test_horizon_daemon_commands_are_not_logged(): void
    {
        $ignored = ['horizon', 'horizon:work', 'horizon:supervisor', 'horizon:snapshot'];

        foreach ($ignored as $command) {
            $this->assertTrue(
                $this->isIgnored($command),
                "{$command} 應被忽略，否則常駐程序被殺掉時會留下 status=0 孤兒列"
            );
        }

        // 對照：一般命令仍要記錄
        $this->assertFalse($this->isIgnored('archive:command_logs'));
    }

    private function isIgnored(string $command): bool
    {
        $reflection = new ReflectionClass(CommandProvider::class);

        return $reflection->getMethod('ignoreCommand')->invoke(
            $reflection->newInstanceWithoutConstructor(),
            $command
        );
    }

    /**
     * 派發手動執行路徑的事件（`CommandStarting` → `CommandFinished`）。
     */
    private function runManualCommand(int $exitCode): CommandLog
    {
        $input = new ArrayInput([]);
        $output = new NullOutput;

        Event::dispatch(new CommandStarting(self::PROBE_COMMAND, $input, $output));
        Event::dispatch(new CommandFinished(self::PROBE_COMMAND, $input, $output, $exitCode));

        return $this->latestProbeLog();
    }

    /**
     * 派發排程路徑的事件（`ScheduledTaskStarting` → `ScheduledTaskFinished`）。
     */
    private function runScheduledTask(?int $exitCode): CommandLog
    {
        $task = $this->makeTask($exitCode);

        Event::dispatch(new ScheduledTaskStarting($task));
        Event::dispatch(new ScheduledTaskFinished($task, 0.5));

        return $this->latestProbeLog();
    }

    private function makeTask(?int $exitCode): SchedulingEvent
    {
        // command 字串比照 routes/console.php 實際產生的形態；ScheduleProvider 以
        // explode("'artisan'", ...) 取出命令名
        $task = new SchedulingEvent(
            app(CacheEventMutex::class),
            "'/usr/local/bin/php' 'artisan' " . self::PROBE_COMMAND
        );
        $task->exitCode = $exitCode;

        return $task;
    }

    private function latestProbeLog(): CommandLog
    {
        $log = CommandLog::query()
            ->where('command', self::PROBE_COMMAND)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log, '找不到 ' . self::PROBE_COMMAND . ' 的 command_logs 記錄');

        return $log;
    }
}
