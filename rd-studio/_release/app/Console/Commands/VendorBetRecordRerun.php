<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Enums\VendorEnum;
use App\Helpers\TimeUsageHelper;
use App\Services\VendorService;
use App\Models\Vendor;
use DB;

class VendorBetRecordRerun extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vendor_bet_record_rerun {--vendor=} {--date=} {--summary-disable=}'; // input date (GMT+8)

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    protected $summaryIsDisable = false;
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
        $this->summaryIsDisable = $this->confirm('Summary is disable?');
        // must input vendor_id
        if (!$this->option('vendor')) {
            echo 'please input option --vendor';
            return;
        }

        $vendor = $this->option('vendor') ? $this->option('vendor') : 'all';
        $date = date('Y-m-d', strtotime('-16 hours')); //GMT+8 yesterday
        if ($this->option('date')) { // input timezone GMT+8 because it is date format
            $date = $this->option('date');
        }

        $isDoVendorHourRerun = true;

        //取得三方遊戲代碼
        $third_party_code = ($vendor > 0)
            ? $this->service->getThirdPartyCodeByVendorId($vendor)
            : 'ALL';

        switch ($third_party_code) {
            case 'ALL':
                $this->dailyRerunProcess($date);
                $isDoVendorHourRerun = false;
                break;
            case VendorEnum::CODE_AG:
                $this->agProcess($date);
                break;
            case VendorEnum::CODE_JOKER:
                $this->jokerProcess($date);
                break;
            case VendorEnum::CODE_SCR:
                $this->scrProcess($date);
                break;
            case VendorEnum::CODE_HAOLONG:
                $this->haolongProcess($date);
                break;
            case VendorEnum::CODE_GW99:
                $this->gw99Process($date);
                break;
            case VendorEnum::CODE_ACE333:
                $this->ace333Process($date);
                break;
            case VendorEnum::CODE_VPLAY:
                $this->vplayProcess($date);
                break;
            case VendorEnum::CODE_MEGA888:
                $this->mega888Process($date);
                break;
            case VendorEnum::CODE_XE88:
                $this->xe88Process($date);
                break;
            case VendorEnum::CODE_LUCKY:
                $this->luckyProcess($date);
                break;
            case VendorEnum::CODE_EBET:
                $this->ebetProcess($date);
                break;
            case VendorEnum::CODE_ISB:
                $this->isbProcess($date);
                break;
            case VendorEnum::CODE_YGG:
                $this->yggProcess($date);
                break;
            case VendorEnum::CODE_WORLDMATCH:
                $this->wmProcess($date);
                break;
            case VendorEnum::CODE_PLAYTECH:
                $this->ptProcess($date);
                break;
            case VendorEnum::CODE_SBO:
                $this->sboProcess($date);
                break;
            case VendorEnum::CODE_MT:
                $this->mtProcess($date);
                break;
            case VendorEnum::CODE_PLAYNGO:
                $this->playngoProcess($date);
                break;
            case VendorEnum::CODE_IA:
                $this->iaProcess($date);
                break;
            case VendorEnum::CODE_VPOWER:
                $this->vpowerProcess($date);
                break;
            case VendorEnum::CODE_PUSSY888:
                $this->pussy888Process($date);
                break;
            case VendorEnum::CODE_PGS:
                $this->pgsProcess($date, $vendor);
                break;
            case VendorEnum::CODE_AESEXY:
                $this->aesProcess($date);
                break;
            case VendorEnum::CODE_IGS:
                $this->igsProcess($date);
                break;
            case VendorEnum::CODE_CT855:
                $this->ctProcess($date);
                break;
            case VendorEnum::CODE_SKY1388:
                $this->sky1388Process($date);
                break;
            case VendorEnum::CODE_DG:
                $this->dgProcess($date);
                break;
            case VendorEnum::CODE_PLAYBOY:
                $this->play8oyProcess($date);
                break;
            case VendorEnum::CODE_RM:
                $this->rmProcess($date);
                break;
            case VendorEnum::CODE_N918:
                $this->n918Process($date);
                break;
            case VendorEnum::CODE_TERRA:
                $this->terraProcess($date);
                break;
            case VendorEnum::CODE_ALLBET:
                $this->allbetProcess($date);
                break;
            case VendorEnum::CODE_VSPOKER:
                $this->vspokerProcess($date);
                break;
            case VendorEnum::CODE_SA:
                $this->saProcess($date);
                break;
            case VendorEnum::CODE_ESPORT:
                $this->esportProcess($date);
                break;
            case VendorEnum::CODE_MENANG88:
                $this->menang88Process($date);
                break;
            case VendorEnum::CODE_NPT:
                $this->nptProcess($date);
                break;
            case VendorEnum::CODE_V8:
                $this->v8Process($date);
                break;
            case VendorEnum::CODE_OBET33:
                $this->obet33Process($date);
                break;
            case VendorEnum::CODE_SABA:
                $this->sabaProcess($date);
                break;
            case VendorEnum::CODE_BOLA:
                $this->bola2Process($date);
                break;
            case VendorEnum::CODE_100PLUS:
                $this->plus100Process($date);
                break;
            case VendorEnum::CODE_SPADEGAMING:
                $this->spadegamingProcess($date);
                break;
            case VendorEnum::CODE_NEXTSPIN:
                $this->nsProcess($date);
                break;
            case VendorEnum::CODE_BNG:
                $this->bngProcess($date);
                break;
            case VendorEnum::CODE_MGC:
                $this->mgcProcess($date);
                break;
            case VendorEnum::CODE_YEEBET:
                $this->ybetProcess($date);
                break;
            case VendorEnum::CODE_VSPORT:
                $this->vsportProcess($date);
                break;
            case VendorEnum::CODE_PRAGMATICPLAY:
                $this->ppProcess($date);
                break;
            case VendorEnum::CODE_PGSLOT:
                $this->pgslotProcess($date);
                break;
            case VendorEnum::CODE_SIAMLOTTO:
                $this->slProcess($date);
                break;
            case VendorEnum::CODE_EVOLUTION:
                $this->evoProcess($date, $vendor);
                break;
            case VendorEnum::CODE_CONECT93:
                $this->c93Process($date);
                break;
            case VendorEnum::CODE_LIVE22:
                $this->l4Process($date);
                break;
            case VendorEnum::CODE_LBB18:
                $this->lbbProcess($date);
                break;
            default:
                echo 'can not find vendor '. $vendor;
                $isDoVendorHourRerun = false;
                break;
        }
        if ($isDoVendorHourRerun) {
            $tuhelper = new TimeUsageHelper();
            $stmt = "call SP_Job_Vendor_Hour_Rerun('" . $date . "', " . $vendor . ")";
            echo $stmt . " start" . PHP_EOL;
            //DB::update($stmt);
            echo $stmt . ' end usage [' . $tuhelper->getUsageSec() . ' s]' . PHP_EOL;
        }
        return;
    }
    public function dailyRerunProcess($date) {
        $time_start = microtime(true);
        //$this->jokerProcess($date);
        //$this->haolongProcess($date);
        //$this->gw99Process($date);
        //$this->ace333Process($date);
        //$this->vplayProcess($date);
        //$this->xe88Process($date);
        //$this->luckyProcess($date);
        //$this->ebetProcess($date);
        //$this->ptProcess($date);
        //$this->sboProcess($date);
        //$this->mtProcess($date);
        //$this->iaProcess($date);
        //$this->pgsProcess($date);
        //$this->aesProcess($date);
        //$this->igsProcess($date);
        //$this->ctProcess($date);
        //$this->sky1388Process($date);
        //$this->play8oyProcess($date);
        //$this->rmProcess($date);
        //$this->n918Process($date);
        //$this->allbetProcess($date);
        //$this->vspokerProcess($date);
        //$this->saProcess($date);
        //$this->esportProcess($date);
        //$this->menang88Process($date);
        //$this->nptProcess($date);
        //$this->v8Process($date);
        //$this->obet33Process($date);
        //$this->plus100Process($date);
        //$this->spadegamingProcess($date);
        //$this->nsProcess($date);
        //$this->bngProcess($date);
        //$this->mgcProcess($date);
        //$this->ybetProcess($date);
        //$this->vsportProcess($date);
        $time_end = microtime(true);
        $execution_time = ($time_end - $time_start)/60;
        echo sprintf("\n --- Run daily process in %.2f Minutes. \n", $execution_time);
    }

    public function agProcess($date) { //input yesterday GMT+8
        $vendor = 1;

        //ag_bet_record_daily 時區是 GMT - 4 ,重跑要抓兩天的數據
        $d = date('Ymd', strtotime($date . ' -1 day'));
        \Artisan::call('ag_bet_record_daily', [
            '--start' => $d
        ]);
        $d = date('Ymd', strtotime($date));
        \Artisan::call('ag_bet_record_daily', [
            '--start' => $d
        ]);

        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function jokerProcess($date) { //input yesterday GMT+8
        $vendor = 2;
        // joker_bet_record input GMT +8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H:00', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H:00', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('joker_bet_record', [
                '--start' => $start,
                '--end' => $end
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function scrProcess($date) {
        $vendor = 3;

        // new_scr_daily_win scr input GMT+8, 一次獲取一天
        \Artisan::call('new_scr_daily_win', [
            '--date' => $date,
        ]);
        // new_scr_bet_record input GMT+8
        \Artisan::call('new_scr_bet_record', [
            '--date' => $date,
        ]);
        //Summary 只需要跑指定的時段, 一次跑一天
        $this->SummaryProcessOnceOfDate($vendor, $date);
    }

    public function haolongProcess($date) { //input yesterday GMT+8
        $vendor = 4;
        // haolong_bet_record 4d input GMT+8, 一次獲取一天
        \Artisan::call('haolong_bet_record', [
            '--date' => $date,
        ]);
        \Artisan::call('haolong_bet_win', [
            '--date' => $date,
        ]);
        \Artisan::call('haolong_daily_win_loss', [
            '--date' => $date,
        ]);

        $start = date('Y-m-d@00', strtotime($date));
        //Summary 只需要跑一次
        \Artisan::call('summary_bet_record', [
            '--start' => $start,
            '--vendor' => $vendor
        ]);
    }

    public function gw99Process($date) { //input yesterday GMT+8
        $vendor = 5;
        // great_wall_bet_record input GMT+8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H:00', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H:00', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('great_wall_bet_record', [
                '--start' => $start,
                '--end' => $end,
            ]);
            \Artisan::call('great_wall_transaction', [
                '--start' => $start,
                '--end' => $end,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function ace333Process($date) { //input yesterday GMT+8
        $vendor = 6;
        $utc_datetime = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        //ace333_bet_record input GMT+0
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($utc_datetime . sprintf("+%d hours", $i)));
            \Artisan::call('ace333_bet_record', [
                '--start' => $start,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function vplayProcess($date) { //input yesterday GMT+8
        $vendor = 8;
        // vplay_bet_record input GMT+8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H:i:s', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H:i:s', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('vplay_bet_record', [
                '--start' => $start,
                '--end' => $end,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function mega888Process($date) { //input yesterday GMT+8
        $vendor = 10;
        // mega888_daily_win input GMT+8
        \Artisan::call('mega888_daily_win', [
            '--date' => $date,
        ]);
        $utc_datetime = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        // mega888_bet_record input GMT+0
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($utc_datetime . sprintf("+%d hours", $i)));
            \Artisan::call('mega888_bet_record', [
                '--hour' => $start,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑一天
        $this->SummaryProcessOnceOfDate($vendor, $date);
    }

    public function xe88Process($date) { //input yesterday GMT+8
        $vendor = 11;
        // xe88_daily_win input GMT+8
        \Artisan::call('xe88_daily_win', [
            '--date' => $date,
        ]);
        // xe88_bet_record input GMT+8
        for ($i=0;$i<48;$i++) {
            $start = date('Y-m-d@H:i', strtotime($date . sprintf("+%d minutes", $i * 30)));
            \Artisan::call('xe88_bet_record', [
                '--start_minute' => $start,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function luckyProcess($date) { //input yesterday GMT+8
        $vendor = 12;
        $utc_datetime = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        // lucky_bet_record input GMT+0
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($utc_datetime . sprintf("+%d hours", $i)));
            \Artisan::call('lucky_bet_record', [
                '--date_hour' => $start,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function ebetProcess($date) { //input yesterday GMT+8
        $vendor = 13;
        // ebet_bet_record --start_date=2019-10-01 input GMT+8
        \Artisan::call('ebet_bet_record', [
            '--start_date' => $date,
        ]);
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function isbProcess($date) { //input yesterday GMT+8
        $vendor = 14;
        $utc_datetime = date('Y-m-d H:i:s', strtotime($date));
        // isb_bet_record input GMT +0
        for ($i=0;$i<48;$i++) {
            $start = date('Y-m-d@H:i', strtotime($utc_datetime . sprintf("+%d minutes", $i * 30)));
            \Artisan::call('isb_bet_record', [
                '--start' => $start,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date));
        $this->SummaryProcess($vendor, $start);
    }

    public function yggProcess($date) { //input yesterday GMT+8
        $vendor = 15;
        $utc_datetime = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        // ygg_bet_record input GMT +0
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H:i:s', strtotime($utc_datetime . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H:i:s', strtotime($utc_datetime . sprintf("+%d hours", $i+1)));
            \Artisan::call('ygg_bet_record', [
                '--start' => $start,
                '--end' => $end,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function wmProcess($date) { //input yesterday GMT+8
        $vendor = 16;
        // sr11 是抓 12:00 - 12:00, 所以 $date 要先 +12
        $date = date('Y-m-d H:i:s', strtotime($date . ' +12 hours'));
        $wm_datetime = date('Y-m-d H:i:s', strtotime($date . ' -12 hours'));
        // wm_bet_record --hour=2020-05-06@00 input GMT -4
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($wm_datetime . sprintf("+%d hours", $i)));
            \Artisan::call('wm_bet_record', [
                '--hour' => $start,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function ptProcess($date) { //input yesterday GMT+8
        $vendor = 17;
        // pt_bet_record input GMT +8
        for ($i=0;$i<48;$i++) {
            $start = date('Y-m-d@H:i', strtotime($date . sprintf("+%d minutes", $i * 30)));
            \Artisan::call('pt_bet_record', [
                '--start' => $start,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function sboProcess($date) { //input yesterday GMT+8
        $vendor = 18;
        $utc_datetime = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        // sbo_bet_record input GMT +0
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($utc_datetime . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H', strtotime($utc_datetime . sprintf("+%d hours", $i+1)));
            \Artisan::call('sbo_bet_record', [
                '--start' => $start,
                '--end' =>$end
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function mtProcess($date) { //input yesterday GMT+8
        $vendor = 19;
        //mt_bet_record input GMT +8, 一次跑一天
        \Artisan::call('mt_bet_record', [
            '--start' => $date,
        ]);
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function playngoProcess($date) { //input yesterday GMT+8
        $vendor = 20;
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function iaProcess($date) { //input yesterday GMT+8
        $vendor = 21;
        // ia_bet_record input GMT +8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            \Artisan::call('ia_bet_record', [
                '--start' => $start,
            ]);
            \Artisan::call('ia_daily_win_loss', [
                '--start' => $start,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function vpowerProcess($date) { //input yesterday GMT+8
        $vendor = 22;
        //vpower_bet_record input GMT +8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H:i:s', strtotime($date . sprintf("+%d hours", $i)));
            \Artisan::call('vpower_bet_record', [
                '--start' => $start,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function pussy888Process($date) { //input yesterday GMT+8
        $vendor = 23;
        //pussy888_bet_record input GMT +8
        \Artisan::call('pussy888_bet_record', [
            '--date' => $date,
        ]);

        //Summary 只需要跑指定的時段, 一次跑一天
        $this->SummaryProcessOnceOfDate($vendor, $date);
    }

    public function pgsProcess($date, $vendor = 0) { //input yesterday GMT+8
        #取得聚合遊戲商所包含VendorId
        $vendor_groups = Vendor::where('third_party_code', 'target')
            ->where('status', 1)
            ->where('is_single_wallet', 0)  //轉帳錢包才抓注單
            ->pluck('id')
            ->toArray();

        if ($vendor == 0) {
            $vendors = $vendor_groups;
        } elseif (in_array($vendor, $vendor_groups)) {
            $vendors = [$vendor];
        } else {
            $vendors = [];
        }

        if (count($vendors) > 0) {
            foreach ($vendors as $vendor_id) {
                $utc_datetime = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
                // aes_bet_record input GMT+0

                for ($i=0;$i<24;$i++) {
                    $start = date('Y-m-d@H:i:s', strtotime($utc_datetime . sprintf("+%d hours", $i)));
                    $end = date('Y-m-d@H:i:s', strtotime($utc_datetime . sprintf("+%d hours", $i+1)));
                    \Artisan::call('pgs_bet_record', [
                        '--vendor' => $vendor_id,
                        '--start' => $start,
                        '--end' => $end
                    ]);
                }

                //Summary 只需要跑指定的時段, 一次跑 24 小時
                $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
                $this->SummaryProcess($vendor_id, $start);
            }
        }
    }

    public function aesProcess($date) { //input yesterday GMT+8
        $vendor = 25;
        $utc_datetime = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        // aes_bet_record input GMT+0
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($utc_datetime . sprintf("+%d hours", $i)));
            \Artisan::call('aes_bet_record', [
                '--start' => $start,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function igsProcess($date) { //input yesterday GMT+8
        $vendor = 26;
        //igs_bet_record input GMT +8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('igs_bet_record', [
                '--start' => $start,
                '--end' => $end
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function ctProcess($date) { //input yesterday GMT+8
        $vendor = 27;
        //igs_bet_record input GMT +8
        \Artisan::call('ct_bet_record');

        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function sky1388Process($date) { //input yesterday GMT+8
        $vendor = 28;
        //igs_bet_record input GMT +8
        for ($i=0;$i<25;$i++) { //sky1388 多跑一個小時來獲取跨日注單
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            \Artisan::call('sky1388_bet_record', [
                '--start' => $start
            ]);
        }

        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function dgProcess($date) { //input yesterday GMT+8
        $vendor = 29;
        //igs_bet_record input GMT +8
        \Artisan::call('dg_bet_record');

        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function play8oyProcess($date) { //input yesterday GMT+8
        $vendor = 30;
        // play8oy_bet_record input GMT+8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('play8oy_bet_record', [
                '--start' => $start,
                '--end' => $end,
            ]);
            \Artisan::call('play8oy_transaction', [
                '--start' => $start,
                '--end' => $end,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function rmProcess($date) {
        $vendor = 32;
        // rm_bet_record input GMT+8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('rm_bet_record', [
                '--start' => $start,
                '--end' => $end,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function n918Process($date) {
        $vendor = 33;
        // n918_bet_record input GMT+8
        // 重跑時一次取一天比較快
        $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", 0)));
        $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", 24)));
        \Artisan::call('n918_bet_record', [
            '--start' => $start,
            '--end' => $end,
        ]);

        $start = date('Y-m-d@00', strtotime($date));
        //Summary 只需要跑一次
        \Artisan::call('summary_bet_record', [
            '--start' => $start,
            '--vendor' => $vendor
        ]);
    }

    public function allbetProcess($date) {
        $vendor = 35;
        // 重跑時一次跑一天比較快，跟n918一樣
        $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", 0)));
        $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", 24)));
        \Artisan::call('allbet_bet_record', [
            '--start' => $start,
            '--end' => $end,
        ]);
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function vspokerProcess($date) { //input yesterday GMT+8
        $vendor = 36;
        //vspoker_bet_record input GMT +8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('vspoker_bet_record', [
                '--start' => $start,
                '--end' => $end
            ]);
        }

        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function saProcess($date) { //input yesterday GMT+8
        $vendor = 37;
        // sa_bet_record input GMT+8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('sa_bet_record', [
                '--start' => $start,
                '--end' => $end,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function esportProcess($date) { //input yesterday GMT+8
        $vendor = 38;
        // esport_bet_record input GMT+8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('esport_bet_record', [
                '--start' => $start,
                '--end' => $end,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function menang88Process($date) { //input yesterday GMT+8
        $vendor = 40;
        // menang88_bet_record input GMT+8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            \Artisan::call('menang88_bet_record', [
                '--start' => $start,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function nptProcess($date)
    { //input yesterday GMT+8
        //npt一次只能半小時
        $vendor = 41;
        // npt_bet_record input GMT+8
        for ($i = 0; $i < 48; $i++) {
            $start = date('Y-m-d@H:i', strtotime($date . sprintf("+%d minutes", 30 * $i)));
            echo $start . PHP_EOL;
            \Artisan::call('npt_bet_record', [
                '--start' => $start,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function v8Process($date) { //input yesterday GMT+8
        $vendor = 42;
        // v8_bet_record input GMT+8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('v8_bet_record', [
                '--start' => $start,
                '--end' => $end,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function obet33Process($date) { //input yesterday GMT+8
        $vendor = 43;
        $date = date('Y-m-d H:i:s', strtotime($date . ' +12 hours'));

        // obet33_bet_record input GMT+8
        \Artisan::call('obet33_bet_record', [
            '--date' => $date
        ]);
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcessOnceOfDate($vendor, $start);
    }

    public function sabaProcess($date) { //input yesterday GMT+8
        $vendor = 44;
        \Artisan::call('saba_bet_record');
        $date = date('Y-m-d H:i:s', strtotime($date . ' +12 hours'));
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcessOnceOfDate($vendor, $start);
    }

    public function bola2Process($date) {
        $vendor = 45;
        // bola2_bet_record input GMT+8
        // 重跑時一次取一天比較快
        $start = date('Y-m-d', strtotime($date . sprintf("+%d hours", 0)));
        $end = date('Y-m-d', strtotime($date . sprintf("+%d hours", 24)));
        \Artisan::call('bola2_bet_record', [
            '--start' => $start,
            '--end' => $end,
        ]);

        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcessOnceOfDate($vendor, $start);
    }

    public function plus100Process($date) { //input yesterday GMT+8
        $vendor = 46;
        // plus100_bet_record input GMT+8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('100plus_bet_record', [
                '--start' => $start,
                '--end' => $end,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function spadegamingProcess($date) { //input yesterday GMT+8
        $vendor = 47;
        // spadegaming_bet_record input GMT+8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('spadegaming_bet_record', [
                '--start' => $start,
                '--end' => $end,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function nsProcess($date) { //input yesterday GMT+8
        $vendor = 48;
        // ns_bet_record input GMT+8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('ns_bet_record', [
                '--start' => $start,
                '--end' => $end,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function bngProcess($date) { //input yesterday GMT+8
        $vendor = 49;
        // bng_bet_record input GMT+8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('bng_bet_record', [
                '--start' => $start,
                '--end' => $end,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function mgcProcess($date) { //input yesterday GMT+8
        $vendor = 51;
        // bng_bet_record input GMT+8
        for ($i=0;$i<24;$i++) {
            $start = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i)));
            $end = date('Y-m-d@H', strtotime($date . sprintf("+%d hours", $i+1)));
            \Artisan::call('mgc_bet_record', [
                '--start' => $start,
                '--end' => $end,
            ]);
        }
        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function ybetProcess($date) { //input yesterday GMT+8
        $vendor = 52;
        //ybet_bet_record input GMT +8
        \Artisan::call('ybet_bet_record');

        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function vsportProcess($date) { //input yesterday GMT+8
        $vendor = 53;
        //igs_bet_record input GMT +8
        \Artisan::call('vsport_bet_record');

        //Summary 只需要跑指定的時段, 一次跑 24 小時
        $start = date('Y-m-d H:i:s', strtotime($date . ' -8 hours'));
        $this->SummaryProcess($vendor, $start);
    }

    public function SummaryProcess($vendor, $start)
    {
        if ($this->summaryIsDisable) {
            return;
        }

        for($i = 0; $i < 24; $i++) {
            $t = date('Y-m-d@H', strtotime($start . sprintf("+%d hours", $i)));
            \Artisan::call('summary_bet_record', [
                '--vendor' => $vendor,
                '--start' => $t
            ]);
        }
    }

    public function SummaryProcessOnceOfDate($vendor, $date) {
        if($this->summaryIsDisable) {
            return;
        }
        $t = date('Y-m-d@H', strtotime($date));
        \Artisan::call('summary_bet_record', [
            '--vendor' => $vendor,
            '--start' => $t
        ]);
    }


}
