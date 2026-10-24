<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SystemLucky extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system_lucky';

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
        for ($i = 38; $i < 49; $i++) {
            $start = date('Y-m-d@', strtotime('2019-10-01 +' . $i . 'day'));
            //重跑ACE333
            for ($j = 0; $j < 24; $j++) {
                $hour = str_pad($j, 2, "0", STR_PAD_LEFT);
                Artisan::call('lucky_bet_record', ['--date_hour' => $start . '@' . $hour]);
            }
        }
    }
}