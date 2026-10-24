<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SystemJoker extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system_joker';

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
        for ($i = 34; $i < 36; $i++) {
            $start = date('Y-m-d', strtotime('2019-10-01 +' . $i . 'day'));

            //重跑Joker
            for ($j = 0; $j < 24; $j++) {
                $hour = str_pad($j, 2, "0", STR_PAD_LEFT);
                $hour_end = str_pad(($j + 2), 2, "0", STR_PAD_LEFT);
                if ($hour_end >= 24) {
                    $end = date('Y-m-d', strtotime('2019-10-01 +' . ($i + 1) . 'day'));
                    Artisan::call('joker_bet_record', ['--start' => $start . '@' . $hour . ':00', '--end' => $end . '@' . str_pad(($hour_end - 24), 2, "0", STR_PAD_LEFT) . ':00']);
                } else {
                    Artisan::call('joker_bet_record', ['--start' => $start . '@' . $hour . ':00', '--end' => $start . '@' . $hour_end . ':00']);
                }
                sleep(1);
            }
        }


        // for ($i = 14; $i < 16; $i++) {
        //     $start = str_pad($i, 2, "0", STR_PAD_LEFT);
        //     $end = str_pad(($i + 1), 2, "0", STR_PAD_LEFT);

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
        // }
    }
}