<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class System extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system {--vendor=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '直接跑指令';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $vendor = $this->option('vendor');
        for ($i = 75; $i < 92; $i++) {
            for ($j = 0; $j < 24; $j++) {
                $hour = str_pad($j, 2, "0", STR_PAD_LEFT);
                //重跑summary bet record
                $start = date('Y-m-d@' . $hour, strtotime('2019-10-01 +' . $i . 'day'));
                echo '---' . $start . '---';
                $data = ['--start' => $start];
                $data2 = ['--dateHour' => $start];
                if ($vendor != '') {
                    $data['--vendor'] = $vendor;
                    $data2['--vendor'] = $vendor;
                }
                Artisan::call('summary_bet_record', $data);
            }
        }

        // for ($i = 1; $i < 16; $i++) {
        //     $start = str_pad($i, 2, "0", STR_PAD_LEFT);
        //     $end = str_pad(($i + 1), 2, "0", STR_PAD_LEFT);
        //     //重跑gw99
        //     Artisan::call('great_wall_bet_record', ['--start' => '2019-10-' . $start . '@00:00:00', '--end' => '2019-10-' . $end . '@00:00:00']);

        //     //重跑AG
        //     Artisan::call('ag_bet_record_daily', ['--start' => '201910' . $start]);

        //     //重跑ACE333
        //     for ($j = 0; $j < 24; $j++) {
        //         $hour = str_pad($j, 2, "0", STR_PAD_LEFT);
        //         Artisan::call('ace333_bet_record', ['--start' => '2019-10-' . $start . '@' . $hour]);
        //     }

        //     //重跑Joker
        //     for ($j = 0; $j < 24; $j++) {
        //         $hour = str_pad($j, 2, "0", STR_PAD_LEFT);
        //         $hour_end = str_pad(($j + 2), 2, "0", STR_PAD_LEFT);
        //         if ($hour_end >= 24) {
        //             Artisan::call('joker_bet_record', ['--start' => '2019-10-' . $start . '@' . $hour . ':00', '--end' => '2019-10-' . ($start + 1) . '@' . ($hour_end - 24) . ':00']);
        //         } else {
        //             Artisan::call('joker_bet_record', ['--start' => '2019-10-' . $start . '@' . $hour . ':00', '--end' => '2019-10-' . $start . '@' . $hour_end . ':00']);
        //         }
        //         sleep(1);
        //     }

        //     //重跑VPLAY
        //     Artisan::call('vplay_bet_record', ['--start' => '2019-10-' . $start . '@00:00:00', '--end' => '2019-10-' . $end . '@00:00:00']);
        // }
    }
}