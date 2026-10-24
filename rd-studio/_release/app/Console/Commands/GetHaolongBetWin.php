<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Helpers\VendorSend;
use App\Models\VendorInfo;
use App\Models\HaolongBetRecord;
use DB;

class GetHaolongBetWin extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'haolong_bet_win {--date=} ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '取豪龍中獎記錄';

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
            $from = $this->option('date') ? $this->option('date') : date('Y-m-d');
            echo 'DATE_FROM:' . $from . PHP_EOL;
            $settings = VendorInfo::where('vendor_id', 4)->where('status', 1)->get();
            $used = []; //已執行過的不再執行
            foreach ($settings as $row) {
                $info = json_decode($row->info);
                if (is_object($info) && !in_array($info->HAOLONG_USER, $used)) {
                    echo '======' . $row->name . '======' . PHP_EOL;
                    $url = $info->API_URL . 'winNumber.aspx';

                    $data = array();
                    $data['dateFrom'] = $from; // 開彩日期
                    $data['dateTo'] = $from;
                    $data['apiUser'] = $info->API_USER;
                    $data['apiPass'] = $info->API_PASSWORD;
                    $data['user'] = $info->HAOLONG_USER;
                    $data['pass'] = $info->HAOLONG_PASSWORD;

                    $result = simplexml_load_string(
                        $this->vendorsend->send('haolong_get_bet_win', $url, 'POST', $data)
                    );
                    echo 'Data Count:' . count($result) . PHP_EOL;
                    if (is_object($result)) {
                        if ($result->errorCode == 0) {
                            $old_bet_id = 0;
                            foreach ($result->betData as $row) {
                                $data = array();
                                $data['draw_date'] = $row->drawDate;
                                $data['strike'] = str_replace(',', '', $row->strike);
                                $record = HaolongBetRecord::where('bet_id', $row->betID)->first();
                                if (is_object($record)) {
                                    //必需先轉為字串，否則會不相等
                                    if ((string) $old_bet_id == (string) $row->betID) {
                                        //因為有機會開出相同中獎號碼，一張注單會中兩次獎，所以彩金需要相加
                                        $record->increment('strike', str_replace(',', '', $row->strike));
                                    } else {
                                        $record->update($data);
                                    }
                                }
                                $old_bet_id = $row->betID;
                                echo '*****' . $row->betID . '*****' . PHP_EOL;
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