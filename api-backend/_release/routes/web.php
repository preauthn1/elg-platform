<?php

/*
 * |--------------------------------------------------------------------------
 * | Web Routes
 * |--------------------------------------------------------------------------
 * |
 * | Here is where you can register web routes for your application. These
 * | routes are loaded by the RouteServiceProvider within a group which
 * | contains the "web" middleware group. Now create something great!
 * |
 */

use App\Helpers\Common;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use App\AES;
use App\Agent;

// Route::post('/encrypt', function (Request $request) {
//     // $des = new DES(env('API_DES_KEY'), 'DES-ECB', DES::OUTPUT_BASE64);
//     // $params = $des->encrypt($request->params);
//     $aes = new AES(env('API_AES_KEY'), env('API_IV'));
//     $params = $aes->encrypt(json_encode($request->all()));
//     $array = array();
//     $array['params'] = $params;
//     $array['key'] = md5($params . env('API_MD5_KEY'));
//     echo json_encode($array);
//     // echo 'Decrypt: ' . $des->decrypt($params);
// });

// Route::get('/decrypt', function (Request $request) {
//     // $des = new DES(env('API_DES_KEY'), 'DES-ECB', DES::OUTPUT_BASE64);
//     // $params = $des->decrypt($request->params);
//     $aes = new AES(env('API_AES_KEY'), env('API_IV'));
//     $params = str_replace(' ', '+', $request->params);
//     $params = $aes->decrypt($params);
//     $array = array();
//     $array['params'] = $params;
//     echo json_encode($array);
// });

Route::get('/vendor_encrypt', function () {
    //info id , created_at
    // $time = '2020-01-09 13:33:48';
    // $time = '2020-01-15 02:00:00';
    //isb測試
    // $array = [
    //     "HOST" => "https://example.invalid/",
    //     "VERSION" => "v1",
    //     "LICENSEE_ID" => "635",
    //     "KEY" => "fPYqkBa2WsY5jxO6x5RHCMnFEYxe4NIK",
    //     "CURRENCY" => "MYR",
    //     "OPERATOR" => "0",
    //     "HTML5_URL" => "https://example.invalid/",
    //     "GAME_LIST_URL" => "https://example.invalid/staging/all_games_everluckgamesmobile_en.xml",
    // ];
    //isb正式
    // $array = [
    //     "HOST" => "https://example.invalid/",
    //     "VERSION" => "v1",
    //     "LICENSEE_ID" => "635",
    //     "KEY" => "6KCoswpTEVAKpPPccuOIiT8wx4p78s7Q",
    //     "CURRENCY" => "THB",
    //     "OPERATOR" => "saf02",
    //     "HTML5_URL" => "https://example.invalid/",
    //     "GAME_LIST_URL" => "https://example.invalid/games/all_games_everluckgamesmobile_en.xml",
    // ];


    // $time = '2020-02-06 16:20:03';
    // // PT 馬幣
    // $array = [
    //     "HOST" => "https://example.invalid/",
    //     "ENTITY_KEY" => "fc2fcb3b588e884afa5f8fede20cc5ac150a92ee721dcabe2679e2dbce36d1a61e0fdee3913821b23b969b469e198a83842d9d137d1d05f35795f267fa22a820",
    //     "CERT_PATH" => "/resources/PlayTech/MYR/MYR",
    //     "ADMIN_NAME" => "ELGSMYRADMIN",
    //     "KIOSK_NAME" => "ELGSMYR",
    //     "MOBILE_DOMAIN" => "example.invalid",
    //     "SYSTEM_ID" => "424",
    // ];

    // $time = '2019-12-13 11:07:10';
    // //AG 馬幣
    // $array = [
    //     "BUSINESS_HOST" => "https://example.invalid/doBusiness.do",
    //     "FORWARD_HOST" => "https://example.invalid/forwardGame.do",
    //     "AGENT" => "GB4_AGIN",
    //     "MD5_KEY" => "kfWZTTcDuNYf",
    //     "DES_KEY" => "TH2zHWe8",
    //     "CURRENCY" => "MYR",
    //     "GAME_TYPE" => "1",
    //     "ODD_TYPE" => "D",
    //     "FTP_URL" => "example.invalid",
    //     "FTP_USERNAME" => "GB4.vworld",
    //     "FTP_PASSWORD" => "kIY8Z4plZg"
    // ];

    // //AG ELG的帳號(泰珠)
    // $time = '2019-12-13 10:44:29';
    // $array = [
    //     "BUSINESS_HOST" => "https://example.invalid/doBusiness.do",
    //     "FORWARD_HOST" => "https://example.invalid/forwardGame.do",
    //     "AGENT" => "GB4_AGIN",
    //     "MD5_KEY" => "kfWZTTcDuNYf",
    //     "DES_KEY" => "TH2zHWe8",
    //     "CURRENCY" => "THB",
    //     "GAME_TYPE" => "1",
    //     "ODD_TYPE" => "A",
    //     "FTP_URL" => "example.invalid",
    //     "FTP_USERNAME" => "GB4.vworld",
    //     "FTP_PASSWORD" => "kIY8Z4plZg"
    // ];
    $aes = md5($time);
    $iv = date('UYm', strtotime($time));
    $aes = new AES($aes, $iv);
    $result = $aes->encrypt(json_encode($array));
    echo  $result;
});

// Route::get('/vendor_decrypt', function () {
//     $time = '2019-12-13 10:44:29';
//     $aes = md5($time);
//     $iv = date('UYm', strtotime($time));
//     $aes = new AES($aes, $iv);
//     $string = 'B1iBv0uAqJc+YrCpu+8o+55H35qUahak+y8daPdnOj863UhoKZC/oFmrgkQcgJURGRtYErjulCLEbiFtEuh//bQWItIPSsawVWMhMQAqD9htycLnF77gKlLpk9mu7UzGvIC3OPVtbWmcCFEoC2vIpl3PhW/P1XdStuD+ZycjyB7g/o7qLpxydBbxnB2o1y85fkmLFNHyIlYRBqazBA364lrP2c2MXOQrpNKWSbz0N5Sk0v6LDiFYB6W4OzuZO4PEzAneyXgPR8EZS+1kOX9blYcEcLwjfnQTzCmmsM6mkZhmDvzoDqafKvNETP3OTuTsQdZLcSIhFvpaLL3VpE8OXme4HvtiD7H9v9nqvClHzB5F/sEZSYhlzy0u3zVOl2oeyoye2KeedCJU3riaAWCgLfCVjXmwrxEhP1xRF3jZUoA=';
//     $result = $aes->decrypt($string);
//     echo  $result;
// });


Route::get('/', function () {
    Common::log(1011, array('api'=>'agent'), 'actionApiBackend');
    return view('welcome');
});


Route::get('/redis', function () {
    Redis::incrby('product:1:sales', 1000);
    Redis::set('product:1:count', 10);
    echo Redis::get('product:1*');
    echo '<br>';
    $visits = Redis::incr('visits');
    echo $visits;
    echo '<br>';

    $mkv = array(
        'usr:0001' => '1',
        'usr:0002' => 'Second user',
        'usr:0003' => 'Third user',
    );
    Redis::mset($mkv); // 存储多个 key 对应的 value
    $retval = Redis::mget(array_keys($mkv));
    var_dump($retval);
    echo '</br>';
    var_dump(Redis::keys('user:0001:*'));
    echo '</br>';
    $user = array(
        'user:0001:id' => '1',
        'user:0001:name' => 'gina.chao',
        'user:0001:email' => 'user@example.invalid',
        'user:0001:mobile' => '0912345678',
    );
    Redis::mset($user);
    $rs = Redis::mget(array_keys($user));
    var_dump($rs);
});

// Route::get('/gameReport', function () {
//     //加IP訪問限制 
//     if (getenv('HTTP_CLIENT_IP') && strcasecmp(getenv('HTTP_CLIENT_IP'), 'unknown')) {
//         $userip = getenv('HTTP_CLIENT_IP');
//         //  echo "1:".$userip."<br>";
//     } elseif (getenv('HTTP_X_FORWARDED_FOR') && strcasecmp(getenv('HTTP_X_FORWARDED_FOR'), 'unknown')) {
//         $userip = explode(",", getenv('HTTP_X_FORWARDED_FOR'))[0];
//         //  echo "2:".$userip."<br>";
//     } elseif (getenv('REMOTE_ADDR') && strcasecmp(getenv('REMOTE_ADDR'), 'unknown')) {
//         $userip = getenv('REMOTE_ADDR');
//         //  echo "3:".$userip."<br>";
//     } elseif (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], 'unknown')) {
//         $userip = $_SERVER['REMOTE_ADDR'];
//         //  echo "4:".$userip."<br>";
//     }
//     $ips = ['0.0.0.0', '0.0.0.0', '0.0.0.0'];
//     if (!in_array($userip, $ips)) {
//         echo '您的IP不在允許存取範圍:' . $userip;
//         exit;
//     }
//     $start_date = '2019-10-01';
//     $total_date = round((date('U') - strtotime("2019-10-01")) / 3600 / 24);
//     //AG
//     //select DATE_FORMAT(bet_time,'%Y-%m-%d'),sum(bet_amount),sum(valid_bet_amount),sum(net_amount) from ag_bet_records group by DATE_FORMAT(bet_time,'%Y-%m-%d');
//     $records = DB::table('ag_bet_records')
//         ->select(DB::raw("DATE_FORMAT(bet_time,'%Y-%m-%d') as date,SUM(bet_amount) as bet,SUM(valid_bet_amount) as vavlid_bet,SUM(net_amount) as net_amount"))
//         ->where('bet_time', '>=', '2019-10-01 00:00:00')
//         ->groupBy(DB::raw("DATE_FORMAT(bet_time,'%Y-%m-%d')"))
//         ->get();
//     $data = array();
//     foreach ($records as $row) {
//         $data[$row->date] = $row;
//     }
//     echo '<table align="left" style="border:8px #FFD382 groove;" cellpadding="10" border="1">';
//     echo '<tr>';
//     echo '<th>AG</th><th>投注額</th><th>有效投注額</th><th>輸贏</th>';
//     echo '</tr>';
//     for ($i = 0; $i < $total_date; $i++) {
//         $date = date('Y-m-d', strtotime($start_date . "+$i day"));
//         echo '<tr align="right">';
//         echo '<td>' . $date . '</td>';
//         echo '<td>' . (isset($data[$date]->bet) ? $data[$date]->bet : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->vavlid_bet) ? $data[$date]->vavlid_bet : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->net_amount) ? $data[$date]->net_amount : 0) . '</td>';
//         echo '</tr>';
//     }
//     echo '</table>';

//     //JOker
//     //select DATE_FORMAT(DATE_ADD(bet_time,INTERVAL 8 HOUR),'%Y-%m-%d'),sum(amount),sum(result),sum(result)-sum(amount) from joker_bet_records group by DATE_FORMAT(DATE_ADD(bet_time,INTERVAL 8 HOUR),'%Y-%m-%d');
//     $records = DB::table('joker_bet_records')
//         ->select(DB::raw("DATE_FORMAT(DATE_ADD(bet_time,INTERVAL 8 HOUR),'%Y-%m-%d') as date,sum(amount) as bet,sum(result) as result,sum(result)-sum(amount) as winlose"))
//         ->where('bet_time', '>=', '2019-09-30 16:00:00')
//         ->groupBy(DB::raw("DATE_FORMAT(DATE_ADD(bet_time,INTERVAL 8 HOUR),'%Y-%m-%d')"))
//         ->get();
//     $data = array();
//     foreach ($records as $row) {
//         $data[$row->date] = $row;
//     }
//     echo '<table align="left" style="border:8px #FFD382 groove;" cellpadding="10" border="1">';
//     echo '<tr>';
//     echo '<th>Joker</th><th>投注額</th><th>贏得金額</th><th>輸贏</th>';
//     echo '</tr>';
//     for ($i = 0; $i < $total_date; $i++) {
//         $date = date('Y-m-d', strtotime($start_date . "+$i day"));
//         echo '<tr align="right">';
//         echo '<td>' . $date . '</td>';
//         echo '<td>' . (isset($data[$date]->bet) ? $data[$date]->bet : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->result) ? $data[$date]->result : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->winlose) ? $data[$date]->winlose : 0) . '</td>';
//         echo '</tr>';
//     }
//     echo '</table>';
//     //918
//     //select `date`,sum(win) from scr_daily_wins group by `date`
//     $records = DB::table('scr_daily_wins')
//         ->select(DB::raw("date,sum(win) as win"))
//         ->where('date', '>=', '2019-10-01')
//         ->groupBy('date')
//         ->get();
//     $data = array();
//     foreach ($records as $row) {
//         $data[$row->date] = $row;
//     }
//     echo '<table align="left" style="border:8px #FFD382 groove;" cellpadding="10" border="1">';
//     echo '<tr>';
//     echo '<th>918 Kiss</th><th>輸贏</th>';
//     echo '</tr>';
//     for ($i = 0; $i < $total_date; $i++) {
//         $date = date('Y-m-d', strtotime($start_date . "+$i day"));
//         echo '<tr align="right">';
//         echo '<td>' . $date . '</td>';
//         echo '<td>' . (isset($data[$date]->win) ? $data[$date]->win : 0) . '</td>';
//         echo '</tr>';
//     }
//     echo '</table>';
//     //豪龍
//     //select `draw_date`,sum(turnover),sum(strike),sum(commission) ,sum(total) from haolong_daily_win_losses group by `draw_date`;
//     $records = DB::table('haolong_daily_win_losses')
//         ->select(DB::raw("draw_date as date,sum(turnover) as bet,sum(strike) as win,sum(commission) as commission,sum(total) as winlose"))
//         ->where('draw_date', '>=', '2019-10-01')
//         ->groupBy(DB::raw("draw_date"))
//         ->get();
//     $data = array();
//     foreach ($records as $row) {
//         $data[$row->date] = $row;
//     }
//     echo '<table align="left" style="border:8px #FFD382 groove;" cellpadding="10" border="1">';
//     echo '<tr>';
//     echo '<th>豪龍</th><th>投注額</th><th>結果</th><th>佣金</th><th>輸贏</th>';
//     echo '</tr>';
//     for ($i = 0; $i < $total_date; $i++) {
//         $date = date('Y-m-d', strtotime($start_date . "+$i day"));
//         echo '<tr align="right">';
//         echo '<td>' . $date . '</td>';
//         echo '<td>' . (isset($data[$date]->bet) ? $data[$date]->bet : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->win) ? $data[$date]->win : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->commission) ? $data[$date]->commission : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->winlose) ? $data[$date]->winlose : 0) . '</td>';
//         echo '</tr>';
//     }
//     echo '</table>';

//     //GW99
//     //select DATE_FORMAT(DATE_ADD(utc_created_time,INTERVAL 8 HOUR),'%Y-%m-%d'),sum(betCoin),sum(winAmount),sum(winAmount)-sum(betCoin),sum(availTotalBet),sum(availTotalWin),sum(availTotalWin)-sum(availTotalBet) from great_wall_bet_records group by DATE_FORMAT(DATE_ADD(utc_created_time,INTERVAL 8 HOUR),'%Y-%m-%d');
//     $records = DB::table('great_wall_bet_records')
//         ->select(DB::raw("DATE_FORMAT(DATE_ADD(utc_created_time,INTERVAL 8 HOUR),'%Y-%m-%d') as date,sum(betCoin) as bet,sum(winAmount) as win,sum(winAmount)-sum(betCoin) as winlose,sum(availTotalBet) as vavlid_bet,sum(availTotalWin) as vavlid_win,sum(availTotalWin)-sum(availTotalBet) as vavlid_winlose"))
//         ->where(DB::raw("DATE_FORMAT(DATE_ADD(utc_created_time,INTERVAL 8 HOUR),'%Y-%m-%d')"), '>=', '2019-10-01')
//         ->groupBy(DB::raw("DATE_FORMAT(DATE_ADD(utc_created_time,INTERVAL 8 HOUR),'%Y-%m-%d')"))
//         ->get();
//     $data = array();
//     foreach ($records as $row) {
//         $data[$row->date] = $row;
//     }
//     echo '<table align="left" style="border:8px #FFD382 groove;" cellpadding="10" border="1">';
//     echo '<tr>';
//     echo '<th>GW99</th><th>投注額</th><th>結果</th><th>輸贏</th><th>有效投注額</th><th>有效結果</th><th>有效輸贏</th>';
//     echo '</tr>';
//     for ($i = 0; $i < $total_date; $i++) {
//         $date = date('Y-m-d', strtotime($start_date . "+$i day"));
//         echo '<tr align="right">';
//         echo '<td>' . $date . '</td>';
//         echo '<td>' . (isset($data[$date]->bet) ? $data[$date]->bet : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->win) ? $data[$date]->win : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->winlose) ? $data[$date]->winlose : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->vavlid_bet) ? $data[$date]->vavlid_bet : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->vavlid_win) ? $data[$date]->vavlid_win : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->vavlid_winlose) ? $data[$date]->vavlid_winlose : 0) . '</td>';
//         echo '</tr>';
//     }
//     echo '</table>';
//     //ACE333
//     //select DATE_FORMAT(DATE_ADD(created,INTERVAL 8 HOUR),'%Y-%m-%d'),sum(betAmount),sum(winAmount),sum(winAmount)-sum(betAmount) from ace333_bet_records group by DATE_FORMAT(DATE_ADD(created,INTERVAL 8 HOUR),'%Y-%m-%d');
//     $records = DB::table('ace333_bet_records')
//         ->select(DB::raw("DATE_FORMAT(DATE_ADD(created,INTERVAL 8 HOUR),'%Y-%m-%d') as date,sum(betAmount) as bet,sum(winAmount+jackpotContributionAmt) as win,sum(winAmount+jackpotContributionAmt)-sum(betAmount) as winlose"))
//         ->where(DB::raw("DATE_FORMAT(DATE_ADD(created,INTERVAL 8 HOUR),'%Y-%m-%d')"), '>=', '2019-10-01')
//         ->where('transactionType', 'BT')
//         ->groupBy(DB::raw("DATE_FORMAT(DATE_ADD(created,INTERVAL 8 HOUR),'%Y-%m-%d')"))
//         ->get();
//     $data = array();
//     foreach ($records as $row) {
//         $data[$row->date] = $row;
//     }
//     echo '<table align="left" style="border:8px #FFD382 groove;" cellpadding="10" border="1">';
//     echo '<tr>';
//     echo '<th>ACE333</th><th>投注額</th><th>結果</th><th>輸贏</th>';
//     echo '</tr>';
//     for ($i = 0; $i < $total_date; $i++) {
//         $date = date('Y-m-d', strtotime($start_date . "+$i day"));
//         echo '<tr align="right">';
//         echo '<td>' . $date . '</td>';
//         echo '<td>' . (isset($data[$date]->bet) ? $data[$date]->bet : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->win) ? $data[$date]->win : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->winlose) ? $data[$date]->winlose : 0) . '</td>';
//         echo '</tr>';
//     }
//     echo '</table>';
//     //VPLAY
//     //select DATE_FORMAT(DATE_ADD(utc_bet_time,INTERVAL 8 HOUR),'%Y-%m-%d'),sum(Bet),sum(WinLose) from vplay_bet_records group by DATE_FORMAT(DATE_ADD(utc_bet_time,INTERVAL 8 HOUR),'%Y-%m-%d');
//     $records = DB::table('vplay_bet_records')
//         ->select(DB::raw("DATE_FORMAT(DATE_ADD(utc_bet_time,INTERVAL 8 HOUR),'%Y-%m-%d') as date,sum(Bet) as bet,sum(WinLose) as winlose"))
//         ->where(DB::raw("DATE_FORMAT(DATE_ADD(utc_bet_time,INTERVAL 8 HOUR),'%Y-%m-%d')"), '>=', '2019-10-01')
//         ->groupBy(DB::raw("DATE_FORMAT(DATE_ADD(utc_bet_time,INTERVAL 8 HOUR),'%Y-%m-%d')"))
//         ->get();
//     $data = array();
//     foreach ($records as $row) {
//         $data[$row->date] = $row;
//     }
//     echo '<table align="left" style="border:8px #FFD382 groove;" cellpadding="10" border="1">';
//     echo '<tr>';
//     echo '<th>VPLAY</th><th>投注額</th><th>輸贏</th>';
//     echo '</tr>';
//     for ($i = 0; $i < $total_date; $i++) {
//         $date = date('Y-m-d', strtotime($start_date . "+$i day"));
//         echo '<tr align="right">';
//         echo '<td>' . $date . '</td>';
//         echo '<td>' . (isset($data[$date]->bet) ? $data[$date]->bet : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->winlose) ? $data[$date]->winlose : 0) . '</td>';
//         echo '</tr>';
//     }
//     echo '</table>';
//     //MEGA888
//     //select `date`,sum(bet),sum(win) from mega888_daily_wins group by `date`;
//     $records = DB::table('mega888_daily_wins')
//         ->select(DB::raw("date,sum(bet) as bet,sum(win) as win"))
//         ->where('date', '>=', '2019-10-01')
//         ->groupBy(DB::raw("date"))
//         ->get();
//     $data = array();
//     foreach ($records as $row) {
//         $data[$row->date] = $row;
//     }
//     echo '<table align="left" style="border:8px #FFD382 groove;" cellpadding="10" border="1">';
//     echo '<tr>';
//     echo '<th>MEGA888</th><th>投注額</th><th>輸贏</th>';
//     echo '</tr>';
//     for ($i = 0; $i < $total_date; $i++) {
//         $date = date('Y-m-d', strtotime($start_date . "+$i day"));
//         echo '<tr align="right">';
//         echo '<td>' . $date . '</td>';
//         echo '<td>' . (isset($data[$date]->bet) ? $data[$date]->bet : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->win) ? ($data[$date]->win * -1) : 0) . '</td>';
//         echo '</tr>';
//     }
//     echo '</table>';
// });


// Route::get('/moneyReport', function () {
//     //加IP訪問限制 
//     if (getenv('HTTP_CLIENT_IP') && strcasecmp(getenv('HTTP_CLIENT_IP'), 'unknown')) {
//         $userip = getenv('HTTP_CLIENT_IP');
//         //  echo "1:".$userip."<br>";
//     } elseif (getenv('HTTP_X_FORWARDED_FOR') && strcasecmp(getenv('HTTP_X_FORWARDED_FOR'), 'unknown')) {
//         $userip = explode(",", getenv('HTTP_X_FORWARDED_FOR'))[0];
//         //  echo "2:".$userip."<br>";
//     } elseif (getenv('REMOTE_ADDR') && strcasecmp(getenv('REMOTE_ADDR'), 'unknown')) {
//         $userip = getenv('REMOTE_ADDR');
//         //  echo "3:".$userip."<br>";
//     } elseif (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], 'unknown')) {
//         $userip = $_SERVER['REMOTE_ADDR'];
//         //  echo "4:".$userip."<br>";
//     }
//     $ips = ['0.0.0.0', '0.0.0.0'];
//     if (!in_array($userip, $ips)) {
//         echo '您的IP不在允許存取範圍:' . $userip;
//         exit;
//     }
//     $start_date = '2019-10-01';
//     $total_date = round((date('U') - strtotime("2019-10-01")) / 3600 / 24);

//     $records = DB::table('transaction_logs')
//         ->select(DB::raw("DATE_FORMAT(DATE_ADD(created_at,INTERVAL 8 HOUR),'%Y-%m-%d') as date, 
// sum(CASE type
// WHEN 3 THEN point 
// ELSE 0
// END) as order_in,
// sum(CASE type
// WHEN 3 THEN point
// WHEN 4 THEN point
// WHEN 10 THEN point
// WHEN 11 THEN point
// WHEN 12 THEN point
// WHEN 13 THEN point
// ELSE 0
// END) as `in`, 
// sum(CASE type
// WHEN 5 THEN point
// ELSE 0
// END) as `out`"))
//         ->where('created_at', '>=', '2019-10-01 04:00:00')
//         ->groupBy(DB::raw("DATE_FORMAT(DATE_ADD(created_at,INTERVAL 8 HOUR),'%Y-%m-%d')"))
//         ->get();
//     $data = array();
//     foreach ($records as $row) {
//         $data[$row->date] = $row;
//     }
//     echo '<table align="left" style="border:8px #FFD382 groove;" cellpadding="10" border="1">';
//     echo '<tr>';
//     echo '<th>日期</th><th>線上儲值</th><th>開分</th><th>洗分</th>';
//     echo '</tr>';
//     for ($i = 0; $i < $total_date; $i++) {
//         $date = date('Y-m-d', strtotime($start_date . "+$i day"));
//         echo '<tr align="right">';
//         echo '<td>' . $date . '</td>';
//         echo '<td>' . (isset($data[$date]->order_in) ? $data[$date]->order_in : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->in) ? $data[$date]->in : 0) . '</td>';
//         echo '<td>' . (isset($data[$date]->out) ? $data[$date]->out : 0) . '</td>';
//         echo '</tr>';
//     }
//     echo '</table>';
// });

// Route::get('/agent_transaction_logs', function () {
//     $data = [
//         ['vworldmy', 'KOE1688', 2, 100, 0, 0, 0, 0, '', 1, '2019-10-5 08:19', '2019-10-5 08:19'],
//         ['vworldmy', 'ET6118', 1, 1000, 0, 0, 0, 0, '', 1, '2019-10-6 11:44', '2019-10-6 11:44'],
//         ['vworldmy', 'ET6118', 2, 2000, 0, 0, 0, 0, '', 1, '2019-10-6 11:45', '2019-10-6 11:45'],
//         ['vworldmy', 'kang001', 1, 593, 0, 0, 0, 0, '', 1, '2019-10-6 14:24', '2019-10-6 14:24'],
//         ['vworldmy', 'ET6118B', 2, 752, 0, 0, 0, 0, '', 1, '2019-10-7 05:32', '2019-10-7 05:32'],
//         ['vworldmy', 'lymsag', 2, 13000, 0, 0, 0, 0, '', 1, '2019-10-7 07:56', '2019-10-7 07:56'],
//         ['vworldmy', 'clv888', 1, 921, 0, 0, 0, 0, '', 1, '2019-10-7 08:04', '2019-10-7 08:04'],
//         ['vworldmy', 'vwcs01hwa', 2, 10, 0, 0, 0, 0, '', 1, '2019-10-8 09:30', '2019-10-8 09:30'],
//         ['vworldmy', 'by9999', 1, 2612, 0, 0, 0, 0, '', 1, '2019-10-8 10:35', '2019-10-8 10:35'],
//         ['vworldmy', 'lyktag', 1, 870, 0, 0, 0, 0, '', 1, '2019-10-9 02:21', '2019-10-9 02:21'],
//         ['vworldmy', 'mmi001', 1, 6000, 0, 0, 0, 0, '', 1, '2019-10-9 03:53', '2019-10-9 03:53'],
//         ['vworldmy', 'vwcs01hwa', 1, 5, 0, 0, 0, 0, '', 1, '2019-10-10 06:01', '2019-10-10 06:01'],
//         ['vworldmy', 'vwcs01hwa', 2, 5, 0, 0, 0, 0, '', 1, '2019-10-10 06:36', '2019-10-10 06:36'],
//         ['vworldmy', 'jjmanj02', 2, 1500, 0, 0, 0, 0, '', 1, '2019-10-10 11:24', '2019-10-10 11:24'],
//         ['vworldmy', 'jjmasa', 1, 1500, 0, 0, 0, 0, '', 1, '2019-10-10 11:26', '2019-10-10 11:26'],
//         ['vworldmy', 'jjmajs11', 2, 1166, 0, 0, 0, 0, '', 1, '2019-10-11 03:24', '2019-10-11 03:24'],
//         ['vworldmy', 'jjmasa', 1, 1166, 0, 0, 0, 0, '', 1, '2019-10-11 03:25', '2019-10-11 03:25'],
//         ['tionghwa', 'jjmajs01', 2, 549, 0, 0, 0, 0, '', 1, '2019-10-11 04:35', '2019-10-11 04:35'],
//         ['tionghwa', 'jjmasa', 1, 549, 0, 0, 0, 0, '', 1, '2019-10-11 04:36', '2019-10-11 04:36'],
//         ['tionghwa', 'jjmajs08', 2, 925, 0, 0, 0, 0, '', 1, '2019-10-11 04:48', '2019-10-11 04:48'],
//         ['tionghwa', 'jjmasa', 1, 925, 0, 0, 0, 0, '', 1, '2019-10-11 04:49', '2019-10-11 04:49'],
//         ['tionghwa', 'jjmasa02', 2, 614, 0, 0, 0, 0, '', 1, '2019-10-11 05:00', '2019-10-11 05:00'],
//         ['tionghwa', 'jjmasa', 1, 614, 0, 0, 0, 0, '', 1, '2019-10-11 05:02', '2019-10-11 05:02'],
//         ['tionghwa', 'jjmasa01', 2, 1121, 0, 0, 0, 0, '', 1, '2019-10-11 05:27', '2019-10-11 05:27'],
//         ['tionghwa', 'jjmasa', 1, 1121, 0, 0, 0, 0, '', 1, '2019-10-11 05:27', '2019-10-11 05:27'],
//         ['tionghwa', 'jjmajs06', 2, 202, 0, 0, 0, 0, '', 1, '2019-10-11 05:35', '2019-10-11 05:35'],
//         ['tionghwa', 'jjmasa', 1, 202, 0, 0, 0, 0, '', 1, '2019-10-11 05:36', '2019-10-11 05:36'],
//         ['tionghwa', 'jjmajs05', 2, 797, 0, 0, 0, 0, '', 1, '2019-10-11 05:38', '2019-10-11 05:38'],
//         ['tionghwa', 'jjmasa', 1, 797, 0, 0, 0, 0, '', 1, '2019-10-11 05:38', '2019-10-11 05:38'],
//         ['tionghwa', 'jjmasa03', 2, 1071, 0, 0, 0, 0, '', 1, '2019-10-11 05:39', '2019-10-11 05:39'],
//         ['tionghwa', 'jjmasa', 1, 1071, 0, 0, 0, 0, '', 1, '2019-10-11 05:39', '2019-10-11 05:39'],
//         ['tionghwa', 'jjmajs07', 2, 806, 0, 0, 0, 0, '', 1, '2019-10-11 05:44', '2019-10-11 05:44'],
//         ['tionghwa', 'jjmasa', 1, 806, 0, 0, 0, 0, '', 1, '2019-10-11 05:45', '2019-10-11 05:45'],
//         ['tionghwa', 'jjmajs03', 2, 393, 0, 0, 0, 0, '', 1, '2019-10-11 06:25', '2019-10-11 06:25'],
//         ['tionghwa', 'jjmajs02', 2, 311, 0, 0, 0, 0, '', 1, '2019-10-11 06:25', '2019-10-11 06:25'],
//         ['tionghwa', 'jjmajs09', 2, 383, 0, 0, 0, 0, '', 1, '2019-10-11 06:26', '2019-10-11 06:26'],
//         ['tionghwa', 'jjmajs12', 2, 500, 0, 0, 0, 0, '', 1, '2019-10-11 06:27', '2019-10-11 06:27'],
//         ['tionghwa', 'jjmasa', 1, 500, 0, 0, 0, 0, '', 1, '2019-10-11 06:27', '2019-10-11 06:27'],
//         ['tionghwa', 'jjmasa', 1, 311, 0, 0, 0, 0, '', 1, '2019-10-11 06:27', '2019-10-11 06:27'],
//         ['tionghwa', 'jjmasa', 1, 383, 0, 0, 0, 0, '', 1, '2019-10-11 06:27', '2019-10-11 06:27'],
//         ['tionghwa', 'jjmasa', 1, 393, 0, 0, 0, 0, '', 1, '2019-10-11 06:28', '2019-10-11 06:28'],
//         ['ivy0728', 'lyktag', 1, 1000, 0, 0, 0, 0, '', 1, '2019-10-11 07:56', '2019-10-11 07:56'],
//         ['tionghwa', 'jjmasa', 2, 8838, 0, 0, 0, 0, '', 1, '2019-10-11 08:28', '2019-10-11 08:28'],
//         ['tionghwa', 'jjam27', 1, 8838, 0, 0, 0, 0, '', 1, '2019-10-11 08:29', '2019-10-11 08:29'],
//         ['tionghwa', 'by999916', 2, 184, 0, 0, 0, 0, '', 1, '2019-10-12 02:17', '2019-10-12 02:17'],
//         ['tionghwa', 'lyktag', 1, 1000, 0, 0, 0, 0, '', 1, '2019-10-12 04:01', '2019-10-12 04:01'],
//         ['tionghwa', 'lyktag', 2, 800, 0, 0, 0, 0, '', 1, '2019-10-12 04:04', '2019-10-12 04:04'],
//         ['ivy0728', 'lyktag', 1, 1000, 0, 0, 0, 0, '', 1, '2019-10-12 08:56', '2019-10-12 08:56'],
//         ['vworldmy', 'xza001', 1, 9950, 0, 0, 0, 0, '', 1, '2019-10-12 12:00', '2019-10-12 12:00'],
//         ['ivy0728', 'bnya01', 1, 1860, 0, 0, 0, 0, '', 1, '2019-10-13 09:28', '2019-10-13 09:28'],
//         ['ivy0728', 'srm035', 2, 500, 0, 0, 0, 0, '', 1, '2019-10-14 03:27', '2019-10-14 03:27'],
//         ['vworldmy', 'srm035', 1, 109540, 0, 0, 0, 0, '', 1, '2019-10-14 04:01', '2019-10-14 04:01'],
//         ['vworldmy', 'jjmjbu60', 2, 357, 0, 0, 0, 0, '', 1, '2019-10-14 08:06', '2019-10-14 08:06'],
//         ['vworldmy', 'jjam28', 1, 357, 0, 0, 0, 0, '', 1, '2019-10-14 08:06', '2019-10-14 08:06'],
//         ['vworldmy', 'jjmjku60', 2, 470, 0, 0, 0, 0, '', 1, '2019-10-14 08:09', '2019-10-14 08:09'],
//         ['vworldmy', 'jjmjku61', 2, 1485, 0, 0, 0, 0, '', 1, '2019-10-14 08:09', '2019-10-14 08:09'],
//         ['vworldmy', 'jjmjku62', 2, 952, 0, 0, 0, 0, '', 1, '2019-10-14 08:09', '2019-10-14 08:09'],
//         ['vworldmy', 'jjmjku63', 2, 315, 0, 0, 0, 0, '', 1, '2019-10-14 08:10', '2019-10-14 08:10'],
//         ['vworldmy', 'jjam28', 1, 3222, 0, 0, 0, 0, '', 1, '2019-10-14 08:10', '2019-10-14 08:10'],
//         ['ivy0728', 'jjam28', 2, 3579, 0, 0, 0, 0, '', 1, '2019-10-14 09:09', '2019-10-14 09:09'],
//         ['ivy0728', 'jjam27', 1, 3579, 0, 0, 0, 0, '', 1, '2019-10-14 09:10', '2019-10-14 09:10'],
//         ['vworldmy', 'lyal01', 1, 20578, 0, 0, 0, 0, '', 1, '2019-10-14 17:01', '2019-10-14 17:01'],
//         ['tionghwa', 'lyal01', 2, 3171, 0, 0, 0, 0, '', 1, '2019-10-15 05:41', '2019-10-15 05:41'],
//         ['tionghwa', 'jjmasa', 2, 1000, 0, 0, 0, 0, '', 1, '2019-10-15 11:40', '2019-10-15 11:40'],
//         ['tionghwa', 'jjmanj03', 1, 1000, 0, 0, 0, 0, '', 1, '2019-10-15 11:41', '2019-10-15 11:41'],
//         ['vworldmy', 'mmi001', 1, 6000, 0, 0, 0, 0, '', 1, '2019-10-16 04:03', '2019-10-16 04:03'],
//         ['ivy0728', 'lyktag', 1, 1200, 0, 0, 0, 0, '', 1, '2019-10-16 05:23', '2019-10-16 05:23'],
//         ['tionghwa', 'CVWBBH66', 2, 10000, 0, 0, 0, 0, '', 1, '2019-10-16 12:04', '2019-10-16 12:04'],
//         ['tionghwa', 'cvwbbh', 1, 10000, 0, 0, 0, 0, '', 1, '2019-10-16 12:04', '2019-10-16 12:04'],
//         ['tionghwa', 'CVWBBH88', 2, 9100, 0, 0, 0, 0, '', 1, '2019-10-16 12:05', '2019-10-16 12:05'],
//         ['tionghwa', 'cvwbbh', 1, 9100, 0, 0, 0, 0, '', 1, '2019-10-16 12:05', '2019-10-16 12:05'],
//         ['tionghwa', 'lyal01', 2, 10000, 0, 0, 0, 0, '', 1, '2019-10-16 12:58', '2019-10-16 12:58'],
//         ['tionghwa', 'srm035', 2, 10000, 0, 0, 0, 0, '', 1, '2019-10-16 13:11', '2019-10-16 13:11'],
//         ['xiaodang28', 'lyktag', 1, 30, 0, 0, 0, 0, '', 1, '2019-10-17 08:14', '2019-10-17 08:14'],
//         ['ying95', 'lysjnag', 1, 500, 0, 0, 0, 0, '', 1, '2019-10-18 14:03', '2019-10-18 14:03'],
//         ['ying95', 'kgaa01', 2, 1300, 0, 0, 0, 0, '', 1, '2019-10-18 14:33', '2019-10-18 14:33'],
//         ['xiaodang28', 'kgaa01', 1, 1501, 0, 0, 0, 0, '', 1, '2019-10-18 14:35', '2019-10-18 14:35'],
//         ['simon90', 'vwcs01simon', 1, 500, 0, 0, 0, 0, '', 1, '2019-10-18 15:28', '2019-10-18 15:28'],
//         ['simon90', 'vwcs01simon', 2, 500, 0, 0, 0, 0, '', 1, '2019-10-18 15:28', '2019-10-18 15:28'],
//         ['simon90', 'kgaa01', 2, 1300, 0, 0, 0, 0, '', 1, '2019-10-18 15:37', '2019-10-18 15:37'],
//         ['tionghwa', 'srm035', 2, 15226, 0, 0, 0, 0, '', 1, '2019-10-19 06:17', '2019-10-19 06:17'],
//         ['tionghwa', 'srm035', 2, 24589, 0, 0, 0, 0, '', 1, '2019-10-19 07:31', '2019-10-19 07:31'],
//         ['tionghwa', 'lyktag', 1, 10, 0, 0, 0, 0, '', 1, '2019-10-19 09:26', '2019-10-19 09:26'],
//         ['tionghwa', 'lyktag', 1, 10, 0, 0, 0, 0, '', 1, '2019-10-19 09:29', '2019-10-19 09:29'],
//         ['tionghwa', 'lyktag', 1, 10, 0, 0, 0, 0, '', 1, '2019-10-19 09:53', '2019-10-19 09:53'],
//         ['tionghwa', 'lyktag', 1, 20, 0, 0, 0, 0, '', 1, '2019-10-19 10:33', '2019-10-19 10:33'],
//         ['vworldmy', 'jjmanj03', 2, 2000, 0, 0, 0, 0, '', 1, '2019-10-20 06:25', '2019-10-20 06:25'],
//         ['vworldmy', 'jjmasa', 1, 2000, 0, 0, 0, 0, '', 1, '2019-10-20 06:26', '2019-10-20 06:26'],
//         ['tionghwa', 'lyktag', 1, 1200, 0, 0, 0, 0, '', 1, '2019-10-20 06:54', '2019-10-20 06:54'],
//         ['tionghwa', 'srm035', 2, 59185, 0, 0, 0, 0, '', 1, '2019-10-20 07:05', '2019-10-20 07:05'],
//         ['elgadmin', 'elgag001', 1, 100, 0, 0, 0, 0, '', 1, '2019-10-20 08:07', '2019-10-20 08:07'],
//         ['vworldmy', 'jjmanj03', 1, 3543, 0, 0, 0, 0, '', 1, '2019-10-20 11:49', '2019-10-20 11:49'],
//         ['vworldmy', 'jjmanj03', 1, 1537, 0, 0, 0, 0, '', 1, '2019-10-20 11:50', '2019-10-20 11:50'],
//         ['vworldmy', 'VWMTEST', 1, 25000, 0, 0, 0, 0, '', 1, '2019-10-21 09:33', '2019-10-21 09:33'],
//         ['tionghwa', 'lyktag', 1, 1000, 0, 0, 0, 0, '', 1, '2019-10-21 10:14', '2019-10-21 10:14'],
//         ['elgadmin', 'elgag001', 1, 1500, 0, 0, 0, 0, '', 1, '2019-10-21 13:24', '2019-10-21 13:24'],
//         ['elgadmin', 'elgag002', 1, 1500, 0, 0, 0, 0, '', 1, '2019-10-21 13:24', '2019-10-21 13:24'],
//         ['elgadmin', 'elgag003', 1, 1500, 0, 0, 0, 0, '', 1, '2019-10-21 13:24', '2019-10-21 13:24'],
//         ['elgadmin', 'elgag003', 1, 10000, 0, 0, 0, 0, '', 1, '2019-10-22 10:35', '2019-10-22 10:35'],
//         ['simon90', 'uuulun23', 1, 30, 0, 0, 0, 0, '', 1, '2019-10-22 16:50', '2019-10-22 16:50'],
//         ['tionghwa', 'jjam28', 2, 5000, 0, 0, 0, 0, '', 1, '2019-10-23 09:57', '2019-10-23 09:57'],
//         ['tionghwa', 'jjam27', 1, 5000, 0, 0, 0, 0, '', 1, '2019-10-23 10:01', '2019-10-23 10:01'],
//         ['tionghwa', 'jjmasa', 2, 3500, 0, 0, 0, 0, '', 1, '2019-10-23 10:03', '2019-10-23 10:03'],
//         ['tionghwa', 'jjam27', 1, 3500, 0, 0, 0, 0, '', 1, '2019-10-23 10:04', '2019-10-23 10:04'],
//         ['tionghwa', 'lyktag', 1, 20, 0, 0, 0, 0, '', 1, '2019-10-23 11:13', '2019-10-23 11:13'],
//         ['tionghwa', 'lyktag', 1, 20, 0, 0, 0, 0, '', 1, '2019-10-23 12:38', '2019-10-23 12:38'],
//         ['simon90', 'lyktag', 1, 30, 0, 0, 0, 0, '', 1, '2019-10-23 14:01', '2019-10-23 14:01'],
//         ['ying95', 'Uuulun32', 1, 1000, 0, 0, 0, 0, '', 1, '2019-10-23 14:18', '2019-10-23 14:18'],
//         ['ying95', 'Uuulun33', 1, 1000, 0, 0, 0, 0, '', 1, '2019-10-23 14:46', '2019-10-23 14:46'],
//         ['VWCS005', 'lyktag', 1, 30, 0, 0, 0, 0, '', 1, '2019-10-24 02:39', '2019-10-24 02:39'],
//         ['VWCS005', 'lyktag', 1, 30, 0, 0, 0, 0, '', 1, '2019-10-24 02:41', '2019-10-24 02:41'],
//         ['VWCS005', 'lyktag', 2, 30, 0, 0, 0, 0, '', 1, '2019-10-24 03:00', '2019-10-24 03:00'],
//         ['VWCS005', 'Uuulun31', 1, 1000, 0, 0, 0, 0, '', 1, '2019-10-24 03:03', '2019-10-24 03:03'],
//         ['vworldmy', 'thjf01', 1, 500, 0, 0, 0, 0, '', 1, '2019-10-24 08:26', '2019-10-24 08:26'],
//         ['tionghwa', 'srm010d', 1, 1000, 0, 0, 0, 0, '', 1, '2019-10-24 09:05', '2019-10-24 09:05'],
//         ['tionghwa', 'jjmasa', 2, 1000, 0, 0, 0, 0, '', 1, '2019-10-24 10:13', '2019-10-24 10:13'],
//         ['tionghwa', 'jjmaju01', 1, 1000, 0, 0, 0, 0, '', 1, '2019-10-24 10:13', '2019-10-24 10:13'],
//         ['tionghwa', 'lyktag', 1, 120, 0, 0, 0, 0, '', 1, '2019-10-24 10:21', '2019-10-24 10:21'],
//         ['elg8888', 'elg999', 1, 1, 0, 0, 0, 0, '', 1, '2019-10-24 10:56', '2019-10-24 10:56'],
//         ['elg8888', 'elg999', 1, 1, 0, 0, 0, 0, '', 1, '2019-10-24 10:56', '2019-10-24 10:56'],
//         ['elg8888', 'elg999', 2, 1, 0, 0, 0, 0, '', 1, '2019-10-24 10:57', '2019-10-24 10:57'],
//         ['tionghwa', 'lyktag', 1, 100, 0, 0, 0, 0, '', 1, '2019-10-25 13:16', '2019-10-25 13:16'],
//         ['simon90', 'lysjnag', 1, 400, 0, 0, 0, 0, '', 1, '2019-10-25 13:34', '2019-10-25 13:34'],
//         ['simon90', 'lyktag', 1, 100, 0, 0, 0, 0, '', 1, '2019-10-25 21:27', '2019-10-25 21:27'],
//         ['tionghwa', 'lyktag', 1, 100, 0, 0, 0, 0, '', 1, '2019-10-26 02:26', '2019-10-26 02:26'],
//         ['tionghwa', 'lyktag', 1, 80, 0, 0, 0, 0, '', 1, '2019-10-26 03:00', '2019-10-26 03:00'],
//         ['vworldmy', 'mmi001', 1, 4000, 0, 0, 0, 0, '', 1, '2019-10-26 03:02', '2019-10-26 03:02'],
//         ['tionghwa', 'lyktag', 1, 40, 0, 0, 0, 0, '', 1, '2019-10-26 05:02', '2019-10-26 05:02'],
//         ['tionghwa', 'Coco888', 1, 500, 0, 0, 0, 0, '', 1, '2019-10-26 06:17', '2019-10-26 06:17'],
//         ['tionghwa', 'kentfang', 1, 1000, 0, 0, 0, 0, '', 1, '2019-10-26 09:10', '2019-10-26 09:10'],
//         ['simon90', 'lyktag', 1, 100, 0, 0, 0, 0, '', 1, '2019-10-26 13:33', '2019-10-26 13:33'],
//         ['tionghwa', 'srm031a', 1, 990, 0, 0, 0, 0, '', 1, '2019-10-27 03:00', '2019-10-27 03:00'],
//         ['tionghwa', 'srm031a', 1, 990, 0, 0, 0, 0, '', 1, '2019-10-27 03:03', '2019-10-27 03:03'],
//         ['tionghwa', 'srm031a', 1, 960, 0, 0, 0, 0, '', 1, '2019-10-27 03:03', '2019-10-27 03:03'],
//         ['tionghwa', 'srm031a', 1, 5940, 0, 0, 0, 0, '', 1, '2019-10-27 08:57', '2019-10-27 08:57'],
//         ['tionghwa', 'srm031a', 1, 5940, 0, 0, 0, 0, '', 1, '2019-10-27 08:57', '2019-10-27 08:57'],
//         ['tionghwa', 'srm031a', 2, 5940, 0, 0, 0, 0, '', 1, '2019-10-27 08:57', '2019-10-27 08:57'],
//         ['simon90', 'lyktag', 1, 20, 0, 0, 0, 0, '', 1, '2019-10-27 22:33', '2019-10-27 22:33'],
//         ['simon90', 'lyktag', 1, 20, 0, 0, 0, 0, '', 1, '2019-10-27 23:59', '2019-10-27 23:59'],
//         ['tionghwa', 'lyktag', 1, 1500, 0, 0, 0, 0, '', 1, '2019-10-28 08:28', '2019-10-28 08:28'],
//         ['tionghwa', 'lyktag', 1, 20, 0, 0, 0, 0, '', 1, '2019-10-28 10:42', '2019-10-28 10:42'],
//         ['tionghwa', 'lyktag', 1, 20, 0, 0, 0, 0, '', 1, '2019-10-28 13:01', '2019-10-28 13:01'],
//         ['tionghwa', 'lyktag', 1, 10, 0, 0, 0, 0, '', 1, '2019-10-28 13:17', '2019-10-28 13:17'],
//         ['simon90', 'lysjnag', 1, 400, 0, 0, 0, 0, '', 1, '2019-10-28 13:28', '2019-10-28 13:28'],
//         ['tionghwa', 'Coco888', 1, 400, -1, -146057, 1100, 700, '', 1, '2019-10-29 06:35', '2019-10-29 06:35'],
//         ['simon90', 'xza001', 2, 10000, -1, -56249, 1450.23, 0, '', 1, '2019-10-30 13:42', '2019-10-30 13:42'],
//         ['simon90', 'lyktag', 1, 20, -1, -51454, 9674.93, 8918, '', 1, '2019-10-31 15:35', '2019-10-31 15:35'],
//         ['ivy0728', '633mcy6996', 2, 330, -1, 10378, 9, 0, '', 1, '2019-11-1 06:41', '2019-11-1 06:41'],
//         ['ivy0728', 'lyktag', 1, 1000, -1, 12088, 10674.93, 9418, '', 1, '2019-11-1 07:17', '2019-11-1 07:17'],
//         ['ying95', 'lysjnag', 1, 500, -1, 20856, 2160, 1530, '', 1, '2019-11-1 13:20', '2019-11-1 13:20'],
//     ];
//     $i = 0;
//     foreach ($data as $row) {
//         $from_agent = Agent::where('username', $row[0])->select('id')->first();
//         // 取接收者代理資料
//         $to_agent = Agent::where('username', $row[1])->select('id')->first();
//         // 代理不存在
//         //寫入transation_log
//         $log = array();
//         $log['from_agent_id'] = is_object($from_agent) ? $from_agent->id : 33893;
//         $log['to_agent_id'] =  is_object($to_agent) ? $to_agent->id : 33893;
//         $log['type'] = $row[2];
//         $log['amount'] = $row[3];
//         $log['from_limit_of_credit'] = $row[4];
//         $log['from_used_credit'] = $row[5];
//         $log['to_limit_of_credit'] = $row[6];
//         $log['to_used_credit'] = $row[7];
//         $log['operator'] =  $row[0];
//         $log['status'] =  $row[9];
//         $log['remark'] = $row[8];
//         $log['created_at'] = $row[10];
//         $log['updated_at'] = $row[11];
//         $i++;
//         if (!DB::table('transaction_log_agents')->insert($log)) {
//             echo 'fail-' . $i . '</br>';
//         }
//     }
// });


// Route::get('/update_hierarchy', function () {
//     $agents = Agent::where('updated_at', '>', '2019-12-03 08:33:32')->get();
//     foreach ($agents as $row) {
//         $result = array(-1, $row->id, $row->parent);
//         $agent = Agent::where('id', $row->parent)->first();
//         if (isset($agent->parent) && $agent->parent != 0) {
//             $result[] = $agent->parent;
//             $agent = Agent::where('id', $agent->parent)->first();
//             if (isset($agent->parent) && $agent->parent != 0) {
//                 $result[] = $agent->parent;
//                 $agent = Agent::where('id', $agent->parent)->first();
//                 if (isset($agent->parent) && $agent->parent != 0) {
//                     $result[] = $agent->parent;
//                     $agent = Agent::where('id', $agent->parent)->first();
//                     if (isset($agent->parent) && $agent->parent != 0) {
//                         $result[] = $agent->parent;
//                         $agent = Agent::where('id', $agent->parent)->first();
//                         if (isset($agent->parent) && $agent->parent != 0) {
//                             $result[] = $agent->parent;
//                             $agent = Agent::where('id', $agent->parent)->first();
//                             if (isset($agent->parent) && $agent->parent != 0) {
//                                 $result[] = $agent->parent;
//                                 $agent = Agent::where('id', $agent->parent)->first();
//                                 if (isset($agent->parent) && $agent->parent != 0) {
//                                     $result[] = $agent->parent;
//                                     $agent = Agent::where('id', $agent->parent)->first();
//                                     if (isset($agent->parent) && $agent->parent != 0) {
//                                         $result[] = $agent->parent;
//                                         $agent = Agent::where('id', $agent->parent)->first();
//                                         if (isset($agent->parent) && $agent->parent != 0) {
//                                             $result[] = $agent->parent;
//                                             $agent = Agent::where('id', $agent->parent)->first();
//                                             if (isset($agent->parent) && $agent->parent != 0) {
//                                                 $result[] = $agent->parent;
//                                                 $agent = Agent::where('id', $agent->parent)->first();
//                                                 if (isset($agent->parent) && $agent->parent != 0) {
//                                                     $result[] = $agent->parent;
//                                                     $agent = Agent::where('id', $agent->parent)->first();
//                                                     if (isset($agent->parent) && $agent->parent != 0) {
//                                                         $result[] = $agent->parent;
//                                                         $agent = Agent::where('id', $agent->parent)->first();
//                                                         if (isset($agent->parent) && $agent->parent != 0) {
//                                                             $result[] = $agent->parent;
//                                                             $agent = Agent::where('id', $agent->parent)->first();
//                                                             if (isset($agent->parent) && $agent->parent != 0) {
//                                                                 $result[] = $agent->parent;
//                                                                 $agent = Agent::where('id', $agent->parent)->first();
//                                                                 if (isset($agent->parent) && $agent->parent != 0) {
//                                                                     $result[] = $agent->parent;
//                                                                     $agent = Agent::where('id', $agent->parent)->first();
//                                                                     if (isset($agent->parent) && $agent->parent != 0) {
//                                                                         $result[] = $agent->parent;
//                                                                         $agent = Agent::where('id', $agent->parent)->first();
//                                                                         if (isset($agent->parent) && $agent->parent != 0) {
//                                                                             $result[] = $agent->parent;
//                                                                             $agent = Agent::where('id', $agent->parent)->first();
//                                                                             if (isset($agent->parent) && $agent->parent != 0) {
//                                                                                 $result[] = $agent->parent;
//                                                                                 $agent = Agent::where('id', $agent->parent)->first();
//                                                                                 if (isset($agent->parent) && $agent->parent != 0) {
//                                                                                     $result[] = $agent->parent;
//                                                                                     $agent = Agent::where('id', $agent->parent)->first();
//                                                                                     if (isset($agent->parent) && $agent->parent != 0) {
//                                                                                         $result[] = $agent->parent;
//                                                                                     }
//                                                                                 }
//                                                                             }
//                                                                         }
//                                                                     }
//                                                                 }
//                                                             }
//                                                         }
//                                                     }
//                                                 }
//                                             }
//                                         }
//                                     }
//                                 }
//                             }
//                         }
//                     }
//                 }
//             }
//         }
//         $result[] = 0;
//         $result[] = -2;
//         echo $row->username . '<br>';
//         echo json_encode($result) . '<br>';
//         $row->update(['hierarchy' => json_encode($result)]);
//     }
// });

// 活動統計
Route::get('/eventReport', function () {
    //加IP訪問限制 
    if (getenv('HTTP_CLIENT_IP') && strcasecmp(getenv('HTTP_CLIENT_IP'), 'unknown')) {
        $userip = getenv('HTTP_CLIENT_IP');
    } elseif (getenv('HTTP_X_FORWARDED_FOR') && strcasecmp(getenv('HTTP_X_FORWARDED_FOR'), 'unknown')) {
        $userip = explode(",", getenv('HTTP_X_FORWARDED_FOR'))[0];
    } elseif (getenv('REMOTE_ADDR') && strcasecmp(getenv('REMOTE_ADDR'), 'unknown')) {
        $userip = getenv('REMOTE_ADDR');
    } elseif (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], 'unknown')) {
        $userip = $_SERVER['REMOTE_ADDR'];
    }
    $ips = ['0.0.0.0', '0.0.0.0', '0.0.0.0', '0.0.0.0', '0.0.0.0'];
    if (!in_array($userip, $ips)) {
        echo '您的IP不在允許存取範圍:' . $userip;
        exit;
    }

    $end_date = date('Y-m-d H:00:00');
    // 累積投注額
    $total_bet = DB::table('vendor_hour_records')->whereBetween('date_hour', ['2019-12-24 16:00:00', $end_date])->whereIn('vendor_id', [1, 11, 5, 12, 2, 6, 8, 15])->select(DB::raw("IFNULL(sum(bet),0) as bet"))->first();
    // 已領獎
    $received = DB::table('prize_members')->whereBetween('prize_id', [31, 38])->where('status', 1)->select(DB::raw("count(id) as number"))->first();
    // 未領獎
    $not_received = DB::table('prize_members')->whereBetween('prize_id', [31, 38])->where('status', 0)->select(DB::raw("count(id) as number"))->first();
    // 投注額 50%, 未達 100%
    $not_reached = DB::table('event_processes as p')->leftJoin('event_details as d', 'd.id', 'p.detail_id')->whereBetween('d.event_id', [31, 38])->whereBetween(DB::raw('p.point-p.received'), [1000, 2000])->select(DB::raw("count(p.id) as number"))->first();
    $total_number = ($received->number + $not_received->number);

    echo '12/25 00:00 ~ ' . date('m/d H:00', strtotime($end_date . '+8hour'));
    echo '<br>';
    echo '<br>';
    echo '符合活動投注額 ' . $total_bet->bet . ' ("約"符合 ' . intval($total_bet->bet / 2000) . ' 次領獎)';
    echo '<br>';
    echo '<br>';
    echo '實際達成 100%&nbsp;&nbsp;&nbsp;x&nbsp;&nbsp;&nbsp;&nbsp;' . $total_number;
    echo '<br>';
    echo '<br>';
    if ($total_number > 0) {
        echo '已領獎&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;x&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $received->number . ' (' . round($received->number / $total_number * 100) . '%)';
    } else {
        echo '已領獎&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;x&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; 0 (0%)';
    }
    echo '<br>';
    echo '<br>';
    if ($total_number > 0) {
        echo '未領獎&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;x&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $not_received->number . ' (' . round($not_received->number / $total_number * 100) . '%)';
    } else {
        echo '未領獎&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;x&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; 0 (0%)';
    }
    echo '<br>';
    echo '<hr>';
    echo '投注額 50%, 未達 100%&nbsp;&nbsp;&nbsp;x&nbsp;&nbsp;&nbsp;' . $not_reached->number;
});

// 驗證玩家轉點及勝分是否正常
// Route::get('/check', function () {
//     ini_set('max_execution_time', 180);
//     //加IP訪問限制 
//     if (getenv('HTTP_CLIENT_IP') && strcasecmp(getenv('HTTP_CLIENT_IP'), 'unknown')) {
//         $userip = getenv('HTTP_CLIENT_IP');
//     } elseif (getenv('HTTP_X_FORWARDED_FOR') && strcasecmp(getenv('HTTP_X_FORWARDED_FOR'), 'unknown')) {
//         $userip = explode(",", getenv('HTTP_X_FORWARDED_FOR'))[0];
//     } elseif (getenv('REMOTE_ADDR') && strcasecmp(getenv('REMOTE_ADDR'), 'unknown')) {
//         $userip = getenv('REMOTE_ADDR');
//     } elseif (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], 'unknown')) {
//         $userip = $_SERVER['REMOTE_ADDR'];
//     }
//     $ips = ['0.0.0.0', '0.0.0.0', '0.0.0.0', '0.0.0.0', '0.0.0.0'];
//     if (!in_array($userip, $ips)) {
//         echo '您的IP不在允許存取範圍:' . $userip;
//         exit;
//     }

//     $start_time = '2019-10-01 04:00:00';
//     $end_time =  '2020-10-31 00:00:00';

//     // 先取點數有異動記錄的帳號
//     $data = DB::table('transaction_logs as l')
//         ->leftJoin('members as m', 'm.id', 'l.member_id')
//         ->leftJoin('agents as a', 'a.id', 'm.parent')
//         // ->where('a.hierarchy', 'like', '%,33893,%')
//         ->whereBetween('l.created_at', [$start_time, $end_time])
//         ->where('l.status', 1000)
//         ->whereIn('l.type', [1, 2])
//         ->select('m.id as member_id', DB::raw('GROUP_CONCAT(DISTINCT l.vendor_id ORDER BY l.vendor_id) as vendor_ids'))
//         ->groupBy('m.id')
//         ->offset(0)->limit(10)
//         ->get();

//     // 輸𦝠記錄只有整天的遊戲商
//     $vendor_array = [3, 4, 10];
//     foreach ($data as $row) {
//         $vendors = explode(',', $row->vendor_ids);
//         foreach ($vendors as $vendor_id) {
//             // 查詢各遊戲商的起始值
//             $start = DB::table('transaction_logs')
//                 ->where('member_id', $row->member_id)
//                 ->where('vendor_id', $vendor_id)
//                 ->whereIn('type', [1, 2])
//                 ->where('status', 1000)
//                 ->whereBetween('created_at',  [$start_time, $end_time])
//                 ->select('point', 'type', 'vendor_balance', 'created_at')
//                 ->orderBy('created_at', 'ASC')
//                 ->first();

//             // 查詢各遊戲商的結尾
//             $end = DB::table('transaction_logs')
//                 ->where('member_id', $row->member_id)
//                 ->where('vendor_id', $vendor_id)
//                 ->whereIn('type', [1, 2])
//                 ->where('status', 1000)
//                 ->whereBetween('created_at',  [$start_time, $end_time])
//                 ->select('point', 'type', 'vendor_balance', 'created_at')
//                 ->orderBy('created_at', 'DESC')
//                 ->first();

//             //如果起始跟結尾一樣的話代表無法驗證，因為只有一筆記錄
//             if (is_object($start) && is_object($end) && $start->created_at != $end->created_at) {
//                 // 查詢起始跟結尾期間的輸贏金額
//                 $model = DB::table('summary_bet_records')
//                     ->where('member_id', $row->member_id)
//                     ->where('vendor_id', $vendor_id);
//                 if (in_array($vendor_id, $vendor_array)) {
//                     //記錄只有整天的遊戲商，就取整天
//                     $model->whereBetween('summary_time', [date('Y-m-d 00:00:00', strtotime($start->created_at)), date('Y-m-d 00:00:00', strtotime($end->created_at))]);
//                 } else {

//                     $model->whereBetween('summary_time', [date('Y-m-d H:00:00', strtotime($start->created_at)), date('Y-m-d H:00:00', strtotime($end->created_at))]);
//                 }

//                 $bet = $model->select(DB::raw('SUM(win_lose) as win_lose'))
//                     ->first();

//                 // 查詢這段期間轉入轉出的金額
//                 $transation = DB::table('transaction_logs')
//                     ->where('member_id', $row->member_id)
//                     ->where('vendor_id', $vendor_id)
//                     ->whereIn('type', [1, 2])
//                     ->where('status', 1000)
//                     ->whereBetween('created_at', [$start->created_at, $end->created_at])
//                     ->select(DB::raw('SUM(CASE WHEN type=1 THEN point ELSE 0 END) as total_in'), DB::raw('SUM(CASE WHEN type=1 THEN point ELSE 0 END) as total_out'))
//                     ->first();

//                 // 驗證期間的轉點金額跟輸贏是否正確
//                 $start_balance =  $start->vendor_balance;
//                 if ($end->type == 1) {
//                     //如果是轉入，就把剛轉入的金額扣掉
//                     $end_balance =  $end->vendor_balance - $end->point;
//                 } else {
//                     $end_balance =  $end->vendor_balance;
//                 }
//                 $win_lose = number_format($bet->win_lose, 2);
//                 $in = $transation->total_in;
//                 $out = $transation->total_out;
//                 $diff = $in - $out + $win_lose;
//                 if ($diff < 0) {
//                     echo '<span style="color:red">';
//                 }
//                 echo '【 玩家編號：' . $row->member_id . ' 】';
//                 echo '【 遊戲商編號：' . $vendor_id . ' 】';
//                 echo $start->created_at . ' to ' . $end->created_at;
//                 echo '【起始餘額：' . $start_balance . '】';
//                 echo '【最後餘額：' . $end_balance . '】';
//                 echo '【輸贏：' . $win_lose . '】 【轉入：' . $in . '】 【轉出：' . $out . '】';
//                 echo '【差額：' . $diff . '】';
//                 echo '</br>';
//                 if ($diff < 0) {
//                     echo '</span>';
//                 }
//             }
//         }

//         // 驗證開洗分數字、遊戲勝分(統計區間內的)、目前餘額(平台拿目前的，遊戲商拿轉點進去最後一筆的)是否對照的起來

//     }
// });