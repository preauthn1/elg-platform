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
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Google\Cloud\Storage\StorageClient;
use League\Flysystem\Filesystem;
use Superbalist\Flysystem\GoogleStorage\GoogleStorageAdapter;
use App\DES;
use App\Member;
use App\Agent;
use App\TransactionLog;

use function GuzzleHttp\json_encode;

// ini_set('max_execution_time', 300);
set_time_limit(300);
// use DB;

// Route::get('/transactionLog', function () {
//     $logs = TransactionLog::where('created_at', '>', '2019-11-01 04:00:00')->whereIn('type', [4, 5])->where('operator', '!=', '')->select('id', 'member_id', 'operator')->get();
//     foreach ($logs as $row) {
//         $agent = Agent::where('username', $row->operator)->first();
//         if (is_object($agent)) {
//             $agent_id = $agent->id;
//         } else if ($row->operator !== '') {
//             //子帳號
//             $agent_id = 33893;
//         } else {
//             $member = Member::find($row->member_id);
//             if (is_object($member)) {
//                 $agent_id = $member->parent;
//             } else {
//                 $agent_id = 0;
//             }
//         }
//         if ($agent_id != 0) {
//             $result = TransactionLog::where('id', $row->id)->update(['agent_id' => $agent_id]);
//             echo $row->id . '***' .  $agent_id . '***' .  $result  . '</br>';
//         } else {
//             echo $row->id . '---' . '</br>';
//         }
//     }
// });

// Route::get('/encrypt', function (Request $request) {
//     $des = new DES(env('API_DES_KEY'), 'DES-ECB', DES::OUTPUT_BASE64);
//     $params = $des->encrypt($request->params);
//     $array = array();
//     $array['params'] = $params;
//     $array['key'] = md5($params . env('API_MD5_KEY'));
//     echo str_replace('\\', '', json_encode($array));
//     // echo 'Decrypt: ' . $des->decrypt($params);
// });

// Route::get('/json', function (Request $request) {
//     // $data['username'] = 'gina';
//     // $data['email'] = 'user@example.invalid';
//     // $data['password'] = '123456';
//     // $data['mobile'] = '0912345678';
//     // $data['country_id'] = 1;
//     // $data['zip_code'] = '250';
//     // $data['district'] = '中正區';
//     // $data['city'] = '台北市';
//     // $data['address'] = '新光三越';
//     $data['id'] = '1';
//     $data['menus'] = array(
//         array(
//             'agent_menu_id' => '1',
//             'action' => 'create,update'
//         ),
//         array(
//             'agent_menu_id' => '2',
//             'action' => 'create,update,delete'
//         ),
//         array(
//             'agent_menu_id' => '4',
//             'action' => 'list'
//         ),
//         array(
//             'agent_menu_id' => '5',
//             'action' => 'list'
//         )
//     );
//     echo json_encode($data, JSON_UNESCAPED_UNICODE);
// });

// Route::get('/gcp', function () {
//     $storage = new StorageClient([
//         'projectId' => env('GOOGLE_CLOUD_PROJECT_ID', 'your-project-id'),
//         'keyFilePath' => env('GOOGLE_CLOUD_KEY_FILE', null)
//     ]);
//     // return $storageClient->signedUrlUploader();
//     // return $storageClient->getServiceAccount();
//     // $storageClient->createBucket('tp-wz3-rd-banner');
//     $bucket = $storage->bucket(env('GOOGLE_CLOUD_STORAGE_BUCKET', 'your-bucket-name'));
//     $adapter = new GoogleStorageAdapter($storage, $bucket);
//     $bucket = $storage->bucket('my-bucket');
//     $object = $bucket->object('my-object');
//     $timestamp = date('U', strtotime('+ 1 day'));
//     // return $object->signedUploadUrl($timestamp);
//     $filesystem = new Filesystem($adapter);

//     $file = 'data:image/png;base64,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';
//     $pos = strpos($file, ';');
//     $type = explode(':', substr($file, 0, $pos))[1];
//     $mime = explode('/', $type);

//     $pathImage = base_path() . '/public/uploads/' . time() . '.' . $mime[1];
//     $file = substr($file, strpos($file, ',') + 1, strlen($file));
//     $dataBase64 = base64_decode($file);
//     file_put_contents($pathImage, $dataBase64);
//     (string) $filesystem->write('test_base64_7.png', fopen($pathImage, 'r'), array(
//         'visibility' => 'public'
//     ));
//     return (string) $adapter->getUrl('test_base64_7.png');
// });

Route::get('/', function () {
    Common::log(1011, array('api'=>'agent'), 'actionApiAgent');
    return view('welcome');
});

// Route::get('/redis', function () {
//     Redis::incrby('product:1:sales', 1000);
//     Redis::set('product:1:count', 10);
//     echo Redis::get('product:1*');
//     echo '<br>';
//     $visits = Redis::incr('visits');
//     echo $visits;
//     echo '<br>';

//     $mkv = array(
//         'usr:0001' => '1',
//         'usr:0002' => 'Second user',
//         'usr:0003' => 'Third user'
//     );
//     Redis::mset($mkv); // 存储多个 key 对应的 value
//     $retval = Redis::mget(array_keys($mkv));
//     var_dump($retval);
//     echo '</br>';
//     var_dump(Redis::keys('user:0001:*'));
//     echo '</br>';
//     $user = array(
//         'user:0001:id' => '1',
//         'user:0001:name' => 'gina.chao',
//         'user:0001:email' => 'user@example.invalid',
//         'user:0001:mobile' => '0912345678'
//     );
//     Redis::mset($user);
//     $rs = Redis::mget(array_keys($user));
//     var_dump($rs);
// });

// Route::get('/pw/{id}/{password}', function ($id, $password) {
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

//     $account = Agent::where('username', $id)->first();
//     if (is_object($account)) {
//         $account->update(['password' => strtoupper(md5($password))]);
//         echo 'OK';
//     } else {
//         echo '帳號不存在';
//     }
// });

// Route::get('/update_hierarchy', function () {
//     $agents = Agent::get();
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

Route::get('/addReturn_point_log/{id}', function ($id) {
    //確認這筆記錄存在，沒補過點
    $row = TransactionLog::where('id', $id)->where('status', 9995)->first();
    if (is_object($row)) {
        //更新此記錄為補過點
        $update = $row->update(['status' => 9000]);
        $member = Member::find($row->member_id);
        if ($update) {
            //新增記錄
            $log = array();
            $log['transaction_no'] = 0;
            $log['agent_id'] = $row->agent_id;
            $log['member_id'] = $row->member_id;
            $log['vendor_id'] = 0;
            $log['type'] = 14; //14:補點
            $log['point'] = $row->point;
            $log['vendor_point'] = 0;
            $log['rate'] = 1;
            $log['balance'] = $member->balance;
            $log['vendor_balance'] = 0;
            $log['status'] = 1000;
            $log['remark'] = $row->id;
            $log['result'] = '補點';
            $log['operator'] = 'system';
            $log['platform'] = 2;
            $log['admin'] = 0;
            if (!TransactionLog::create($log)) {
                echo '資料庫操作失敗';
            } else {
                echo '成功';
            }
        } else {
            echo '狀態更新失敗';
        }
    } else {
        echo '記錄不存在';
    }
});