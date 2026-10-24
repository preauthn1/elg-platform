<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\ScrDailyWin;
use DB;

class GetScrDailyWin extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scr_daily_win {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '更新918 kiss每日贏分';

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
        try {
            $url = 'reports';
            $date = $this->option('date') ? $this->option('date') : date('Y-m-d', strtotime('+7hours50minute'));
            // $date = date('Y-m-d', strtotime('+7hours50minute'));
            $data = array();
            $data['operation'] = 'totalreport';
            $data['startdate'] = $date;
            $data['enddate'] = $date;
            $result = $this->post($url, $data);
            if (is_array($result) && $result['returncode'] == 0) {
                ScrDailyWin::where('date', $date)->delete();
                foreach ($result['player_bets'] as $row) {
                    $data = array();
                    $data['date'] = $date;
                    $data['player_id'] = $row['playerid'];
                    $data['win'] = $row['win'];
                    // $win = ScrDailyWin::where('date', $date)->where('player_id', $row['playerid'])->first();
                    // if (!is_object($win)) {
                    ScrDailyWin::create($data);
                    echo '*****' . $row['playerid'] . '*****';
                    // } else {
                    //     ScrDailyWin::where('date', $date)->where('player_id', $row['playerid'])->update($data);
                    //     echo '-------' . $row['playerid'] . '-------';
                    // }
                }
                echo 'sucess';
            } else {
                echo 'fail';
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            echo $info[0] . ' : ' . $info[2];
        } catch (\Exception $e) {
            echo $e->getCode() . ' : ' . $e->getMessage();
        }
    }

    private function post($url, $data)
    {
        $data['apiuserid'] = env('SCR_API_USER');
        $data['apipassword'] = env('SCR_API_PASSWORD');
        Common::log(0, $data, 'scr_daily_win');
        $data_string = json_encode($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('SCR_URL') . $url);
        // curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string)
        ));
        $result = curl_exec($ch);
        Common::log(0, $result, 'scr_daily_win');
        curl_close($ch);
        return json_decode($result, true);
    }
}