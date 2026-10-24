<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SystemMega888 extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system_mega888';

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

        for ($i = 17; $i < 35; $i++) {
            //重跑summary bet record
            $start = date('Y-m-d', strtotime('2019-10-01 +' . $i . 'day'));
            echo '---' . $start . '---';
            Artisan::call('mega888_daily_win', ['--date' => $start]);
        }
    }
}