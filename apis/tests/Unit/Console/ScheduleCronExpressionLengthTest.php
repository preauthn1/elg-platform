<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Console/ScheduleCronExpressionLengthTest.php
 *
 * 守衛：所有排程的 cron 表達式必須塞得進 command_logs.cronjob varchar(20)。
 * ScheduleProvider 在 ScheduledTaskStarting 就把表達式寫進該欄，超長會拋 QueryException、任務被判 FAIL、
 * 命令本體從未執行——而且 command_logs 裡完全沒有痕跡，只能去 scheduler 容器 log 才看得到
 * （dev 2026-09-17：score:expire 用 '2,12,22,32,42,52 * * * *' 24 字，三小時零執行）。
 */
class ScheduleCronExpressionLengthTest extends TestCase
{
    /** 與 2025_09_08_074831_create_command_logs_table 的 cronjob 欄位長度一致 */
    private const int CRONJOB_COLUMN_LENGTH = 20;

    public function test_every_schedule_expression_fits_command_logs_cronjob_column(): void
    {
        $tooLong = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => mb_strlen($event->expression) > self::CRONJOB_COLUMN_LENGTH)
            ->map(fn ($event) => sprintf('%s → %s（%d 字）', $event->command, $event->expression, mb_strlen($event->expression)))
            ->values()
            ->all();

        $this->assertSame([], $tooLong, sprintf(
            "以下排程的 cron 表達式超過 command_logs.cronjob varchar(%d)，排程觸發時會在寫紀錄那步炸掉、命令永遠不跑：\n%s",
            self::CRONJOB_COLUMN_LENGTH,
            implode("\n", $tooLong),
        ));
    }
}
