<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Models\CommandLog;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PDOException;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * command_logs 寫不進去時，命令必須照常執行（fail-soft）
 *
 * php artisan test tests/Unit/Providers/CommandLogFailSoftTest.php --compact
 *
 * ## 這組測試在守什麼
 * `CommandProvider` 在每條業務命令啟動時 `CommandLog::create()`、結束時 `update()`。
 * 本機經 dbc 代理連正式庫時只有 SELECT 權限，這兩筆寫入必然失敗；原本沒有 try/catch，
 * 連純讀的 artisan（對帳診斷、dry-run、報表）都會在啟動時被 `PDOException` 炸掉。
 * 修正後：寫入失敗只記一行 warning，命令照跑；收尾時不再碰 DB、上下文照樣清掉。
 *
 * ## 為何用 model event 模擬失敗
 * 測試庫可寫，無法自然重現 SELECT-only；改在 `creating` / `updating` 拋出 `PDOException`，
 * 與 PDO 在 read_only 連線上寫入時拋出的型別一致。事件派發方式比照
 * {@see CommandExitCodeStatusTest}（`Artisan::call()` 於測試中不會派發這兩個事件）。
 */
class CommandLogFailSoftTest extends TestCase
{
    private const string PROBE_COMMAND = 'probe:fail-soft';

    protected function tearDown(): void
    {
        // 只清本檔掛上的 model event，不動其他測試
        CommandLog::flushEventListeners();

        parent::tearDown();
    }

    #[TestDox('啟動時 command_logs INSERT 失敗：不拋出、記 warning、命令流程繼續')]
    public function test_insert_failure_does_not_abort_command(): void
    {
        CommandLog::creating(function (): void {
            throw new PDOException('SQLSTATE[HY000]: General error: 1290 The MySQL server is running with the --read-only option');
        });

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'command_logs 寫入失敗')
                && $context['command'] === self::PROBE_COMMAND
                && str_contains($context['error'], 'read-only'));
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('withContext')->zeroOrMoreTimes()->andReturnSelf();
        Log::shouldReceive('withoutContext')->zeroOrMoreTimes()->andReturnSelf();

        $input = new ArrayInput([]);
        $output = new NullOutput;

        // 兩個事件都不得拋出；Finished 走到「logId 為 null」的分支，只清上下文
        Event::dispatch(new CommandStarting(self::PROBE_COMMAND, $input, $output));
        Event::dispatch(new CommandFinished(self::PROBE_COMMAND, $input, $output, 0));

        $this->assertNull(
            CommandLog::query()->where('command', self::PROBE_COMMAND)->first(),
            '寫入被模擬拒絕，不應留下任何 command_logs 列'
        );
    }

    #[TestDox('收尾時 command_logs UPDATE 失敗：不拋出、記 warning、啟動列維持原狀')]
    public function test_update_failure_does_not_abort_command(): void
    {
        CommandLog::updating(function (): void {
            throw new PDOException('SQLSTATE[HY000]: General error: 1290 The MySQL server is running with the --read-only option');
        });

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'command_logs 更新失敗'));
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('withContext')->zeroOrMoreTimes()->andReturnSelf();
        Log::shouldReceive('withoutContext')->zeroOrMoreTimes()->andReturnSelf();

        $input = new ArrayInput([]);
        $output = new NullOutput;

        Event::dispatch(new CommandStarting(self::PROBE_COMMAND, $input, $output));
        Event::dispatch(new CommandFinished(self::PROBE_COMMAND, $input, $output, 0));

        $log = CommandLog::query()->where('command', self::PROBE_COMMAND)->orderByDesc('id')->first();

        $this->assertNotNull($log, '啟動時的 INSERT 正常，應有一列');
        $this->assertSame(0, (int) $log->status, 'UPDATE 被模擬拒絕，status 應維持初始值');
    }
}
