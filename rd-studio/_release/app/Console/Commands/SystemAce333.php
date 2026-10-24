<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SystemAce333 extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system_ace333';

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
        for ($i = 1; $i < 16; $i++) {
            $start = str_pad($i, 2, "0", STR_PAD_LEFT);
            $end = str_pad(($i + 1), 2, "0", STR_PAD_LEFT);

            //重跑ACE333
            for ($j = 0; $j < 24; $j++) {
                $hour = str_pad($j, 2, "0", STR_PAD_LEFT);
                Artisan::call('ace333_bet_record', ['--start' => '2019-10-' . $start . '@' . $hour]);
            }
        }
    }
}