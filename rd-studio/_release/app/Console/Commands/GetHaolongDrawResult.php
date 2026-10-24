<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\HaolongBetRecord;
use DB;

class GetHaolongDrawResult extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'haolong_draw_result {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '取獲取開彩成績';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    protected $vendorsend;

    public function __construct()
    {
        parent::__construct();
        $this->vendorsend = new VendorSend();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            $draw_date = $this->option('date') ? $this->option('date') : date('Y-m-d');
            echo '開彩日期:' . $draw_date . PHP_EOL;
            $data = array();
            $data['drawDate'] = $draw_date; //開彩日期
            $this->getDrawResult($data);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function getDrawResult($postData, $tryAgain = true)
    {
        $settings = VendorInfo::where('vendor_id', 4)->where('status', 1)->first();
        $info = json_decode($settings->info);
        $url = $info->API_URL . 'result.aspx';
        $postData['apiUser'] = $info->API_USER;
        $postData['apiPass'] = $info->API_PASSWORD;
        $postData['user'] = $info->HAOLONG_USER;
        $postData['pass'] = $info->HAOLONG_PASSWORD;
        $result = simplexml_load_string($this->vendorsend->send('Haolong_get_draw_date', $url, 'POST', $postData));
        echo '*****' . $result . '*****' . PHP_EOL;
        if (is_object($result)) {
            if ($result->errorCode == 0) {
                foreach ($result->drawResult as $row) {
                    $data = array();
                    $data['draw_type'] = $row->drawType;
                    $data['bet_type'] = $row->betType;
                    $data['draw_date'] = $row->drawDate;
                    $data['drawNumber1'] = $row->drawNumber1;
                    //if (!$record = HaolongBetRecord::where('bet_id', $row->betID)->first()) {
                        //HaolongBetRecord::create($data);
                        //echo '*****' . $row->betID . '*****' . PHP_EOL;
                    //}
                }
            } else {
                echo 'Error:' . $result->desc . PHP_EOL;
            }
        }
    }
}