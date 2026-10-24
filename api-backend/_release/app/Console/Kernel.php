<?php
namespace App\Console;

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
        // 查看AG帳號是否足夠，不夠就建立(每小時執行)
        // $schedule->command('create_ag_account')
        // ->hourly()
        // ->withoutOverlapping();

        // 取AG投注記錄(每15分鐘執行)
        // $schedule->command('ag_bet_record')->cron('*/15 * * * *');

        // 每個月預先建立LOG表(每個月11號凌晨01:03分執行)
        // $schedule->command('create_log_table')->cron('* * * * *');
        $schedule->command('create_log_table')->cron('3 1 11 * *');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
