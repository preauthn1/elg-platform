<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SystemHaolong extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system_haolong';

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
        for ($i = 0; $i < 35; $i++) {
            $start = date('Y-m-d', strtotime('2019-10-01 +' . $i . 'day'));
            Artisan::call('haolong_daily_win_loss', ['--date' => $start]);
        }
    }
}