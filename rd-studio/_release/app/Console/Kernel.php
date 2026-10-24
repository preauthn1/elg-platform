<?php

namespace App\Console;

use App\Models\ScheduleLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{

    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [ //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // 每分鐘推播一次
        //$schedule->command('firebase_send_message')->everyMinute()->withoutOverlapping();

        $this->syncJobs($schedule);
        $this->vendorJobs($schedule);
        $this->paymentJobs($schedule);
        $this->eventJobs($schedule);
        $this->checkJobs($schedule);
        $this->summaryJobs($schedule);
    }

    // 同步相關的 job 放在這個 function 裡.
    protected function syncJobs(Schedule $schedule) {
        if (env('DISABLED_SYNC_JOB', false)) {
            return;
        }
        // 每5分鐘同步一次代理商帳號
        //$schedule->command('synchronize_agent')->everyFiveMinutes()->withoutOverlapping();
    }

    // 代收代付相關的 job 放在這個 function 裡.
    protected function paymentJobs(Schedule $schedule) {
        if (env('DISABLED_PAYMENT_JOB', false)) {
            return;
        }
        // 代付自動完成
        //$schedule->command('payment_auto_completed')->everyMinute()->withoutOverlapping();
    }

    // 遊戲商與支付相關的 job 放在這個 function 裡.
    protected function vendorJobs(Schedule $schedule) {
        if (env('DISABLED_VENDOR_JOB', false)) {
            return;
        }

        // Vpower
        //$schedule->command('get_vpower_games')->dailyAt('02:13')->withoutOverlapping();
        //$schedule->command('create_vpower_account')->everyFiveMinutes()->withoutOverlapping();

        // SpadeGaming
        $schedule->command('get_spadegaming_games')->dailyAt('02:38')->withoutOverlapping();
        $schedule->command('create_spadegaming_account')->everyFiveMinutes()->withoutOverlapping();

        //NextSpin
        $schedule->command('get_ns_games')->dailyAt('02:43')->withoutOverlapping();
        $schedule->command('create_ns_account')->everyFiveMinutes()->withoutOverlapping();

        // PGS_SW
        $schedule->command('get_pgssw_games')->dailyAt('02:32')->withoutOverlapping();
        $schedule->command('create_pgssw_account')->everyFiveMinutes()->withoutOverlapping();

        // PragmaticPlay
        $schedule->command('get_pp_games')->dailyAt('03:03')->withoutOverlapping();
        $schedule->command('create_pp_account')->everyFiveMinutes()->withoutOverlapping();

        // Booongo (st8)
        $schedule->command('get_st8_games')->dailyAt('03:13')->withoutOverlapping();
        $schedule->command('create_st8_account')->everyFiveMinutes()->withoutOverlapping();

        // 確認遊戲商API是否正常(每分鐘一次)
        //$schedule->command('vendor_detect')->everyMinute()->withoutOverlapping();
    }

    // 彙整統計加總相關的 job 放在這個 function 裡.
    protected function summaryJobs(Schedule $schedule)
    {
        if (env('DISABLED_SUMMARY_JOB', false)) {
            return;
        }
        // 每日結算(每小時的30分結算前一個小時的)
        $schedule->command('summary_bet_record')->hourlyAt(30)->withoutOverlapping();
        // 每天重新結算一次
        $schedule->command('vendor_summary_daily_rerun')->dailyAt('01:00')->withoutOverlapping();
    }

    // 活動相關的 job 放在這個 function 裡.
    protected function eventJobs(Schedule $schedule) {
        if (env('DISABLED_EVENT_JOB', false)) {
            return;
        }

        //統計活動數據及給獎(每15分鐘執行)
        $schedule->command('summary_event')->cron('*/15 * * * *')->withoutOverlapping();
    }

    // 檢查報警相關的 job 放在這個 function 裡.
    protected function checkJobs(Schedule $schedule) {
        if (env('DISABLED_CHECK_JOB', false)) {
            return;
        }
        //check_member_bet 每4小時執行一次
        $schedule->command('check_member_bet')->cron('0 */4 * * *')->withoutOverlapping();
        $schedule->command('tg_summary_report')->cron('0 */4 * * *')->withoutOverlapping();
        $schedule->command('check_telegram_message')->everyMinute()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        \Event::listen('Illuminate\Console\Events\CommandStarting', function ($event) {
            //echo $event->command . "---------" . PHP_EOL;
            $GLOBALS["command"] = $event->command;
            if ($event->command == 'some:cmnd') {
                // do stuff on start
            }
        });
        \Event::listen('Illuminate\Console\Events\CommandFinished', function ($event) {
            $usage_ms = intval((microtime(true) - LARAVEL_START) * 1000);
            $now = \DateTime::createFromFormat('U.u', microtime(true));
            $start = \DateTime::createFromFormat('U.u', LARAVEL_START);
            $model = new ScheduleLog();
            $model->schedule_name = $event->command;
            $model->input = $event->input;
            $model->exit_code = $event->exitCode;
            $model->start_time = $start;
            $model->end_time = $now;
            $model->usages = $usage_ms / 1000;
            $model->save();
            //echo $now->format("m-d-Y H:i:s.u");
            //echo $event->command;
            if ($event->command == 'some:cmnd') {
                // do stuff on start
            }
        });

        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}