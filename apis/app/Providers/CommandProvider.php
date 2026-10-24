<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\General\ContextKeyEnum;
use App\Helpers\Common;
use App\Models\CommandLog;
use App\Traits\HasCommandTracing;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use PDOException;
use Symfony\Component\Console\Output\ConsoleOutput;

class CommandProvider extends ServiceProvider
{
    use HasCommandTracing;

    /**
     * 用於暫存目前命令的 CommandLog id
     */
    private static array $logIdStack = [];

    /**
     * 用於暫存目前命令的追蹤碼
     */
    private static array $traceIdStack = [];

    /**
     * CommandLog 狀態值（與 {@see ScheduleProvider} 一致）
     */
    private const int STATUS_SUCCESS = 1;

    private const int STATUS_FAILED = -1;

    /**
     * 更新目前的 CommandLog 狀態
     *
     * @param  int  $exitCode  命令的結束碼，非 0 即視為失敗
     */
    private function updateCurrentLog(int $exitCode): void
    {
        $logId = array_pop(self::$logIdStack);
        $traceId = array_pop(self::$traceIdStack);

        if (!$logId || !$traceId) {
            // 啟動時寫不進 command_logs（logId 為 null）也會走到這裡：只清上下文，不再碰 DB
            $this->clearLogContext();

            return;
        }

        try {
            $log = CommandLog::find($logId);
        } catch (QueryException|PDOException $e) {
            $this->warnCommandLogUnavailable('讀取', $traceId, $e);
            $this->clearLogContext();

            return;
        }

        if (!$log) {
            $this->clearLogContext();

            return;
        }

        // 計算執行時長
        $usagesSeconds = $log->start_time->diffInSeconds(Carbon::now());
        $formattedDuration = $this->formatDuration($usagesSeconds, 2);

        $succeeded = $exitCode === 0;

        // 更新資料庫記錄；寫不進去只記 warning，不能讓已跑完的命令在收尾時炸掉
        try {
            $log->update([
                'status' => $succeeded ? self::STATUS_SUCCESS : self::STATUS_FAILED,
                'end_time' => now()->toDateTimeString(),
                'usages' => $formattedDuration,
            ]);
        } catch (QueryException|PDOException $e) {
            $this->warnCommandLogUnavailable('更新', $traceId, $e);
        }

        // 記錄結果日誌
        if ($succeeded) {
            $this->logCommandFinish($traceId, $log->command, $log->id, $formattedDuration);
        } else {
            $this->logCommandFailed($traceId, $log->command, $log->id, $formattedDuration, [
                'exit_code' => $exitCode,
            ]);
        }

        // 清除日誌上下文
        $this->clearLogContext();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->isLocal() && $this->app->runningInConsole()) {
            $this->logSqlToConsole();
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // CI 編譯過程還未能取得 DB 連線資訊，故先加入此判斷
        if (!file_exists(base_path('.env'))) {
            return;
        }

        $this->registerCommandEvents();
    }

    /**
     * 註冊命令事件監聽器
     */
    private function registerCommandEvents(): void
    {
        Event::listen(CommandStarting::class, function ($event) {
            $this->handleCommandStarting($event);
        });

        Event::listen(CommandFinished::class, function ($event) {
            // 排除排程觸發的命令
            if ($this->ignoreCommand($event->command)) {
                return;
            }

            if (!Schema::hasTable('command_logs')) {
                return;
            }

            // ⚠️ 狀態由 exit code 決定，不可寫死成功——原本恆為 1，導致失敗的手動命令
            // 在 command_logs 讀起來與成功無異
            // ⚠️ exitCode 為 null 不等於失敗：背景任務（runInBackground）此時尚未寫入 exit code，
            // 結果另由 ScheduledBackgroundTaskFinished 回報（本專案未使用），讀 log 判定時別當失敗算
            $this->updateCurrentLog($event->exitCode);
        });
    }

    /**
     * 處理命令開始事件
     */
    private function handleCommandStarting($event): void
    {
        // 排除排程觸發的命令
        if ($this->ignoreCommand($event->command)) {
            return;
        }

        if (!Schema::hasTable('command_logs')) {
            return;
        }

        $now = now();

        // 生成追蹤碼
        $traceId = $this->generateTraceId('ART');
        Context::addHidden(ContextKeyEnum::REQUEST_ID->value, $traceId);

        // 設置日誌上下文
        $this->setLogContext($traceId, $event->command, 'artisan');

        // 清理輸入中的單引號
        $cleanInput = (string) Str::replace('\'', '', $event->input);

        // 創建資料庫記錄。
        // command_logs 是追蹤紀錄，不是命令的前置條件：寫不進去（例如本機經 dbc 代理只有
        // SELECT 權限、或 DB 暫時不可寫）只記 warning，命令照常執行；stack 仍推入 null 佔位，
        // 讓巢狀命令的 CommandFinished 配對不會錯位。
        try {
            $log = CommandLog::create([
                'day' => $now->toDateString(),
                'command' => $event->command,
                'input' => $cleanInput,
                'start_time' => $now,
                'trace_id' => $traceId,
            ]);
        } catch (QueryException|PDOException $e) {
            $this->warnCommandLogUnavailable('寫入', $traceId, $e, $event->command);

            self::$logIdStack[] = null;
            self::$traceIdStack[] = $traceId;

            return;
        }

        // 記錄開始日誌
        $this->logCommandStart($traceId, $event->command, $log->id, [
            'input' => $cleanInput,
        ]);

        self::$logIdStack[] = $log->id;
        self::$traceIdStack[] = $traceId;
    }

    /**
     * command_logs 不可用時的統一告警：只記一行 warning，不拋出、不中斷命令
     */
    private function warnCommandLogUnavailable(string $action, string $traceId, \Throwable $e, ?string $command = null): void
    {
        Log::warning("command_logs {$action}失敗，命令照常執行", [
            'command' => $command,
            'trace_id' => $traceId,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * 可略過不紀錄的命令
     *
     * ⚠️ 一次排程執行會產生 3 筆記錄：ScheduleProvider 寫父程序（CRON_ trace）、
     * 本 Provider 再各寫一筆 `schedule:run` 與子程序 `php artisan <cmd>`（ART_ trace）。
     * 要縮減得先決定「一次執行該留幾筆稽核」，不是單純把 schedule:run 加進名單。
     */
    private function ignoreCommand(?string $command): bool
    {
        if ($command === null) {
            return false;
        }

        $ignoreCommands = [
            // 'about',
            'clear-compiled',
            'completion',
            'db',
            'docs',
            'down',
            'env',
            'help',
            'inspire',
            'list',
            'migrate',
            'optimize',
            'pail',
            'serve',
            'test',
            'tinker',
            'up',
            // 'schedule:run',  // 刻意保留紀錄（非漏寫）——見上方 docblock 的「一次執行 3 筆」
            // Horizon 全系列（原本只列 snapshot/purge/terminate）：常駐的 horizon /
            // horizon:work / horizon:supervisor 被 supervisor 殺掉時不會派發 CommandFinished，
            // 每筆都留成 status=0 的孤兒列。2026-07-29 實測 VWorld 9 天內累積 542 筆
            'horizon',
            'horizon:*',
            'queue:work',
            'queue:restart',
            'queue:listen',
            'ide-helper:generate',
            'ide-helper:meta',
            'key:generate',
            'package:discover',
            'config:cache',
            'route:cache',
            'view:cache',
            // ⚠️ 這條會吃掉所有 test: 前綴命令 ⇒ 寫測試用的探針命令不能取名 test:xxx，
            // 否則不會寫進 command_logs，測起來像功能壞掉
            'test:*',
        ];

        foreach ($ignoreCommands as $pattern) {
            if (Str::is($pattern, $command)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 打印 SQL 到控制台
     */
    private function logSqlToConsole(): void
    {
        if (!config('custom.log_sql')) {
            return;
        }

        DB::listen(function ($query) {
            $sql = Common::queryToSql($query->sql, $query->bindings);
            $output = new ConsoleOutput;
            $output->writeln($sql);
            $output->writeln('--------------------------------------------------------------');
        });
    }
}
