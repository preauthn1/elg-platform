<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\VendorSend;
use App\Helpers\Common;
use App\Models\VendorInfo;
use App\Models\HaolongDailyWinLoss;
use DB;

class GetHaolongDailyWinLoss extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'haolong_daily_win_loss {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '取豪龍每日各玩家(代理)輸贏金額';

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
            $date = $this->option('date') ? $this->option('date') : date('Y-m-d'); // 結算日期
            $settings = VendorInfo::where('vendor_id', 4)->where('status', 1)->get();
            echo 'DATE_FROM:' . $date . PHP_EOL;
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                $info = json_decode($row->info);
                if (is_object($info) && !in_array($info->HAOLONG_USER, $used)) {
                    echo '======' . $row->name . '======' . PHP_EOL;
                    $url = $info->API_URL . 'winLoss.aspx';

                    $data = array();
                    $data['dateFrom'] = $date; // 取當天天的結果
                    $data['dateTo'] = $date;
                    $data['apiUser'] = $info->API_USER;
                    $data['apiPass'] = $info->API_PASSWORD;
                    $data['user'] = $info->HAOLONG_USER;
                    $data['pass'] = $info->HAOLONG_PASSWORD;
                    $result = simplexml_load_string(
                        $this->vendorsend->send('Haolong_get_daily_win_loss', $url, 'POST', $data)
                    );
                    echo 'Data Count:' . count($result) . PHP_EOL;
                    if (is_object($result)) {
                        if ($result->errorCode == 0) {
                            foreach ($result->winLoss as $row) {
                                $data = array();
                                $data['draw_date'] = $date;
                                $data['login_id'] = $row->loginName;
                                $data['turnover'] = str_replace(',', '', $row->turnover);
                                $data['strike'] = str_replace(',', '', $row->strike);
                                $data['commission'] = str_replace(',', '', $row->commission);
                                $data['total'] = str_replace(',', '', $row->total);
                                if (!HaolongDailyWinLoss::where('login_id', $row->loginName)->where('draw_date', $date)->first()) {
                                    HaolongDailyWinLoss::create($data);
                                } else {
                                    HaolongDailyWinLoss::where('login_id', $row->loginName)->where('draw_date', $date)->update($data);
                                }
                                echo '*****' . $row->loginName . '*****' . PHP_EOL;
                            }
                        } else {
                            echo $result->desc . PHP_EOL;
                        }
                    } else {
                        echo 'fail' . PHP_EOL;
                    }

                    $used[] = $info->HAOLONG_USER;
                }
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo 'QueryException:' . PHP_EOL;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            echo 'Exception:' . PHP_EOL;
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }
}