<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use App\Services\VendorService;

class VendorSummaryDailyRerun extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vendor_summary_daily_rerun {--date=} {--vendor=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '重跑summary';

    protected $service;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->service = new VendorService();
    }

    /**
     * Execute the console command.
     * 
     * @return mixed
     */
    public function handle()
    {
        $date = date('Y-m-d', strtotime('-16 hours')); //GMT+8 yesterday
        if ($this->option('date')) { // input timezone GMT+8 because it is date format
            $date = $this->option('date');
        }

        $only_vendor_id = $this->option('vendor') ? $this->option('vendor') : 0;

        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        // 1 => 12 - 12
        // 4, 10, 23, 33 => daily
        // 16 => 12 - 12

        $hourly_vendors = array(2, 3, 5, 6, 8, 11, 12, 13, 15, 17, 18, 19, 20, 21, 22, 24, 25, 26, 27, 28, 30, 32, 35, 36, 37, 41);
        $hourly_vendors = $this->service->getHourlyVendors($only_vendor_id);
        foreach($hourly_vendors as $vendor_id) {
            if ($only_vendor_id != 0 && $only_vendor_id != $vendor_id) {
                continue;
            }
            for($i = 0; $i < 24; $i++) {
                $t = date('Y-m-d@H', strtotime($start . sprintf("+%d hours", $i)));
                \Artisan::call('summary_bet_record', [
                    '--vendor' => $vendor_id,
                    '--start' => $t
                ]);
            }
        }

        $daily_vendors = array(4, 10, 23, 33);
        $daily_vendors = $this->service->getDailyVendors($only_vendor_id);
        foreach($daily_vendors as $vendor_id) {
            if ($only_vendor_id != 0 && $only_vendor_id != $vendor_id) {
                continue;
            }
            $t = date('Y-m-d@H', strtotime($start));
            \Artisan::call('summary_bet_record', [
                '--vendor' => $vendor_id,
                '--start' => $t
            ]);
        }

        if ($only_vendor_id == 0 || $only_vendor_id == 1) {
            // AG SR11 -12
            for ($i = 0; $i < 24; $i++) {
                $j = $i - 4;
                $t = date('Y-m-d@H', strtotime($start . sprintf("+%d hours", $j)));
                \Artisan::call('summary_bet_record', [
                    '--vendor' => 1,
                    '--start' => $t
                ]);
            }
        }

        if ($only_vendor_id == 0 || $only_vendor_id == 16) {
            // WM SR11 +12
            for ($i = 0; $i < 24; $i++) {
                $j = $i + 12;
                $t = date('Y-m-d@H', strtotime($start . sprintf("+%d hours", $j)));
                \Artisan::call('summary_bet_record', [
                    '--vendor' => 16,
                    '--start' => $t
                ]);
            }
        }

        if ($only_vendor_id == 0 || $only_vendor_id == 14) {
            // ISB SR11 +8
            for ($i = 0; $i < 24; $i++) {
                $j = $i + 8;
                $t = date('Y-m-d@H', strtotime($start . sprintf("+%d hours", $j)));
                \Artisan::call('summary_bet_record', [
                    '--vendor' => 14,
                    '--start' => $t
                ]);
            }
        }
    }


}