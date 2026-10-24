<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\General\ContextKeyEnum;
use App\Facades\BotNotify;
use App\Models\CommandLog;
use App\Traits\HasCommandTracing;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class ScheduleProvider extends ServiceProvider
{
    use HasCommandTracing;

    /**
     * 用於暫存目前命令的 CommandLog id
     */
    private static ?int $currentLogId = null;

    /**
     * 用於暫存目前命令的追蹤碼
     */
    private static ?string $currentTraceId = null;

    /**
     * CommandLog 狀態值
     *
     * `command_logs.status` 預設 0（建立時尚未有結果），終結事件才寫入下列之一。
     */
    private const int STATUS_SUCCESS = 1;

    private const int STATUS_FAILED = -1;

    private const int STATUS_SKIPPED = -2;

    /**
     * 耗時任務告警閾值（秒）
     */
    private const int SLOW_TASK_THRESHOLD = 15;

    /**
     * 長時間排程：耗時本來就超過 {@see self::SLOW_TASK_THRESHOLD}，不納入耗時告警（command 名稱）。
     */
    private const array SLOW_TASK_IGNORE_LIST = [
        'update_game_list',
        // 逐一對待恢復的轉帳錢包遊戲商呼叫三方 getBalance()，耗時取決於三方回應速度
        'vendor_detect',
        // 逐商重跑前一整天：轉帳錢包先拉一整天注單再跑 24 小時彙總，本來就以分鐘計
        'summary_bet_record:rerun-full-day',
    ];

    /**
     * 更新目前的 CommandLog 狀態
     */
    private function updateCurrentLog($event, int $status): void
    {
        if (!self::$currentLogId || !self::$currentTraceId) {
            return;
        }

        $log = CommandLog::find(self::$currentLogId);

        if (!$log) {
            $this->clearLogContext();
            self::$currentLogId = null;
            self::$currentTraceId = null;

            return;
        }

        // 計算執行時長
        $usagesSeconds = $event->runtime ?? $log->start_time->diffInSeconds(Carbon::now());
        $formattedDuration = $this->formatDuration($usagesSeconds);

        // 更新資料庫記錄
        $log->update([
            'status' => $status,
            'end_time' => now()->toDateTimeString(),
            'usages' => $formattedDuration,
        ]);

        // 根據狀態記錄不同的日誌
        match ($status) {
            self::STATUS_FAILED => $this->handleFailedTask($log, $formattedDuration),
            self::STATUS_SKIPPED => $this->logCommandSkipped(
                self::$currentTraceId,
                $log->command,
                $log->id
            ),
            default => $this->handleSuccessfulTask($log, $event->runtime ?? 0, $formattedDuration),
        };

        // 清理狀態
        $this->clearLogContext();
        self::$currentLogId = null;
        self::$currentTraceId = null;
    }

    /**
     * 由 exit code 決定 `ScheduledTaskFinished` 該寫入的狀態。
     *
     * ## 為何不能直接寫成功
     * Laravel 的 `ScheduleRunCommand::runEvent()` **先** dispatch `ScheduledTaskFinished`，
     * 之後才 `if ($event->exitCode != 0) throw` 並由 catch 去 dispatch `ScheduledTaskFailed`。
     * 而 {@see self::updateCurrentLog()} 收尾會把 `self::$currentLogId` 清成 null，
     * 使隨後的 `Failed` listener 在開頭早退 ⇒ **`STATUS_FAILED` 結構上永遠寫不進去**
     * （2026-07-29 實測：VWorld 99 萬列、TPower 79 萬列，`status = -1` 皆為 0 筆）。
     *
     * 所以失敗與否必須在這裡自行由 exit code 判定，不能依賴 `Failed` 事件。
     *
     * ## `null` 視為成功
     * 背景任務（`runInBackground()`）的 exit code 由 `Event::run()` 的
     * `if (!$this->runInBackground)` 排除，此時尚未寫入、值為 `null`；
     * 該情境的結果另由 `ScheduledBackgroundTaskFinished` 回報（本專案未使用），
     * 在此保持舊行為不誤判為失敗。
     */
    private function resolveFinishedStatus(?int $exitCode): int
    {
        if ($exitCode === null) {
            return self::STATUS_SUCCESS;
        }

        return $exitCode === 0 ? self::STATUS_SUCCESS : self::STATUS_FAILED;
    }

    /**
     * 處理失敗的任務
     *
     * 排程無人看管，且 `logCommandFailed()` 只寫 `Log::error`；相對地「執行過久」反而有
     * BotNotify（見 {@see self::handleSuccessfulTask()}）。失敗比過久嚴重，一併補上告警。
     */
    private function handleFailedTask(CommandLog $log, string $formattedDuration): void
    {
        $this->logCommandFailed(
            self::$currentTraceId,
            $log->command,
            $log->id,
            $formattedDuration
        );

        BotNotify::channel('warn')->send(sprintf(
            "⚠️ 排程執行失敗\n" .
            "命令: %s\n" .
            "追蹤碼: %s\n" .
            '耗時: %s',
            $log->command,
            self::$currentTraceId,
            $formattedDuration
        ));
    }

    /**
     * 處理成功完成的任務
     */
    private function handleSuccessfulTask(CommandLog $log, float $runtime, string $formattedDuration): void
    {
        // 記錄完成日誌
        $this->logCommandFinish(
            self::$currentTraceId,
            $log->command,
            $log->id,
            $formattedDuration
        );

        // 耗時任務告警（忽略名單內的命令不告警）
        if ($runtime > self::SLOW_TASK_THRESHOLD && !in_array($log->command, self::SLOW_TASK_IGNORE_LIST, true)) {
            BotNotify::channel('warn')->send(sprintf(
                "⚠️ 排程執行時間過長\n" .
                "命令: %s\n" .
                "追蹤碼: %s\n" .
                "耗時: %.2f 秒\n" .
                '閾值: %d 秒',
                $log->command,
                self::$currentTraceId,
                $runtime,
                self::SLOW_TASK_THRESHOLD
            ));
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

        $this->registerScheduledTaskEvents();
    }

    /**
     * 註冊排程任務事件監聽器
     */
    private function registerScheduledTaskEvents(): void
    {
        Event::listen(ScheduledTaskStarting::class, function ($event) {
            $this->handleTaskStarting($event);
        });

        Event::listen(ScheduledTaskSkipped::class, function ($event) {
            if (Schema::hasTable('command_logs')) {
                $this->updateCurrentLog($event, self::STATUS_SKIPPED);
            }
        });

        // 保留當後援：`Event::run()` 自身拋例外（非命令回非零 exit code）時才會走到這裡，
        // 那條路徑不會先派發 Finished，故 $currentLogId 仍在
        Event::listen(ScheduledTaskFailed::class, function ($event) {
            if (Schema::hasTable('command_logs')) {
                $this->updateCurrentLog($event, self::STATUS_FAILED);
            }
        });

        // ⚠️ 狀態由 exit code 決定，不可寫死成功——理由見 resolveFinishedStatus()
        Event::listen(ScheduledTaskFinished::class, function ($event) {
            if (Schema::hasTable('command_logs')) {
                $this->updateCurrentLog($event, $this->resolveFinishedStatus($event->task->exitCode));
            }
        });
    }

    /**
     * 處理任務開始事件
     */
    private function handleTaskStarting($event): void
    {
        if (!Schema::hasTable('command_logs')) {
            return;
        }

        $now = now();

        // 解析命令和輸入
        $arr = explode("'artisan'", $event->task->command);
        $input = trim(end($arr));
        $command = explode(' ', $input);
        $command = reset($command);

        // 生成追蹤碼
        $traceId = $this->generateTraceId('CRON');
        Context::addHidden(ContextKeyEnum::REQUEST_ID->value, $traceId);
        self::$currentTraceId = $traceId;

        // 設置日誌上下文
        $this->setLogContext($traceId, $command, 'schedule');

        // 創建資料庫記錄
        $log = CommandLog::create([
            'day' => $now->toDateString(),
            'command' => $command,
            'input' => $input,
            'start_time' => $now->toDateTimeString(),
            'cronjob' => $event->task->expression,
            'trace_id' => $traceId,
        ]);

        // 記錄開始日誌
        $this->logCommandStart($traceId, $command, $log->id, [
            'input' => $input,
            'cronjob' => $event->task->expression,
        ]);

        self::$currentLogId = $log->id;
    }
}
