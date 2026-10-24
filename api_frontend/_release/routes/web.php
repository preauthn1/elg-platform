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
use Google\Cloud\Storage\StorageClient;
use League\Flysystem\Filesystem;
use Superbalist\Flysystem\GoogleStorage\GoogleStorageAdapter;
use App\AES;
use App\VendorJoker;
use App\Models\PrizeMember;
use App\Models\Member;

// Route::get('/encrypt', function (Request $request) {
//     // $des = new DES(env('API_DES_KEY'), 'DES-ECB', DES::OUTPUT_BASE64);
//     // $params = $des->encrypt($request->params);
//     $aes = new AES(env('THIRD_PARTY_AES_KEY'), env('THIRD_PARTY_IV'));
//     $params = $aes->encrypt($request->params);
//     $array = array();
//     $array['params'] = $params;
//     $array['key'] = md5($params . env('THIRD_PARTY_MD5_KEY'));
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
//     return $adapter->getUrl('xsg_logo.png');
//     $filesystem = new Filesystem($adapter);
//     // return (string) $filesystem->write('xsg_logo.png', 'xsg_logo');
//     return (string) $filesystem->getUrl('xsg_logo.png');
// });

Route::get('/', function () {
    Common::log(1011, array('foo'=>'bar'), 'actionToLog');
    return view('welcome');
});

Route::get('/myip', function () {
    echo Common::getIp();
    //Common::log(1011, array('foo'=>'bar'), 'actionToLog');
    return view('welcome');
});

Route::get('/testLock', 'VendorController@testLock');
Route::get('/testLockWait', 'VendorController@testLockWait');

Route::get('/wait20', function () {
    sleep(20);
    //Common::log(1011, array('foo'=>'bar'), 'actionToLog');
    return view('welcome');
});

// Route::get('/mail', function () {
//     $data = array(
//         "test" => 123456
//     );
//     $email = 'user@example.invalid';
//     Mail::send('email.welcome', $data, function ($message) use ($email) {
//         $message->subject('hello world');
//         $message->to($email);
//     });
//     $failures = [];
//     if (count(Mail::failures()) > 0) {
//         return Mail::failures()[0];
//     } else {
//         return 'Success';
//     }
//     return $failures;
// });

// Route::get('/showTable', function () {
//     $tables = DB::select('SHOW TABLES');
//     return $tables;
// });

// Route::get('/table/{table_name}', function ($table_name) {
//     $data = DB::select('SELECT * FROM ' . $table_name);
//     return $data;
// });

Route::get('/redis/{type}', function ($type) {

    //加IP訪問限制
    if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
        $userip = $_SERVER["HTTP_CF_CONNECTING_IP"];
    } elseif (getenv('HTTP_CLIENT_IP') && strcasecmp(getenv('HTTP_CLIENT_IP'), 'unknown')) {
        $userip = getenv('HTTP_CLIENT_IP');
    } elseif (getenv('HTTP_X_FORWARDED_FOR') && strcasecmp(getenv('HTTP_X_FORWARDED_FOR'), 'unknown')) {
        $userip = explode(",", getenv('HTTP_X_FORWARDED_FOR'))[0];
    } elseif (getenv('REMOTE_ADDR') && strcasecmp(getenv('REMOTE_ADDR'), 'unknown')) {
        $userip = getenv('REMOTE_ADDR');
    } elseif (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], 'unknown')) {
        $userip = $_SERVER['REMOTE_ADDR'];
    }

    $ips = ['0.0.0.0', '0.0.0.0'];
    if (!in_array($userip, $ips)) {
        echo '您的IP不在允許存取範圍: ' . $userip;
        exit;
    }
    // var_dump(Redis::keys('*'));
    // echo Redis::del('Backend:user:menu:1');
    $keys = Redis::keys('*');
    var_dump($keys);
    if ($type == 2) {
        foreach ($keys as $key) {
            \Redis::del($key);
        }
    }
    exit();
    Redis::incrby('product:1:sales', 1000);
    Redis::set('product:1:count', 10);
    // echo Redis::get('product:1*');
    echo Redis::get('product:1:count');
    echo '<br>';
    echo Redis::get('product:1:sales');
    echo '<br>';
    $visits = Redis::incr('visits');
    echo $visits;
    echo '<br>';

    $mkv = array(
        'usr:0001' => '1',
        'usr:0002' => 'Second user',
        'usr:0003' => 'Third user'
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
        'user:0001:mobile' => '0912345678'
    );
    Redis::mset($user);
    $rs = Redis::mget(array_keys($user));
    var_dump($rs);
});

/* 假的訂單更新 */
// Route::get('order/update/{id}', 'OrderController@update');

// Route::get('/joker/{game_type}', function ($game_type) {
//     $joker = new VendorJoker();
//     return $joker->getGameUrl('qa00000003', $game_type);
// });

// Route::get('/event/clear/{id}', function ($id) {
//     PrizeMember::where('member_id', $id)->update(['content_id' => null, 'status' => 0]);
//     echo 'bye';
// });


Route::get('/pw/{id}/{password}', function ($id, $password) {
    //加IP訪問限制 
    if (getenv('HTTP_CLIENT_IP') && strcasecmp(getenv('HTTP_CLIENT_IP'), 'unknown')) {
        $userip = getenv('HTTP_CLIENT_IP');
        //  echo "1:".$userip."<br>";
    } elseif (getenv('HTTP_X_FORWARDED_FOR') && strcasecmp(getenv('HTTP_X_FORWARDED_FOR'), 'unknown')) {
        $userip = explode(",", getenv('HTTP_X_FORWARDED_FOR'))[0];
        //  echo "2:".$userip."<br>";
    } elseif (getenv('REMOTE_ADDR') && strcasecmp(getenv('REMOTE_ADDR'), 'unknown')) {
        $userip = getenv('REMOTE_ADDR');
        //  echo "3:".$userip."<br>";
    } elseif (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], 'unknown')) {
        $userip = $_SERVER['REMOTE_ADDR'];
        //  echo "4:".$userip."<br>";
    }
    $ips = ['0.0.0.0', '0.0.0.0'];
    if (!in_array($userip, $ips)) {
        echo '您的IP不在允許存取範圍:' . $userip;
        exit;
    }

    $account = Member::where('username', $id)->first();
    if (is_object($account)) {
        $account->update(['password' => strtoupper(md5($password))]);
        echo 'OK';
    } else {
        echo '帳號不存在';
    }
});