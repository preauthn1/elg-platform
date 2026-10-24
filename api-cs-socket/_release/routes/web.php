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

use App\User;

Route::get('/', function () {
    return view('welcome');
});

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

    $account = User::where('username', $id)->first();
    if (is_object($account)) {
        $account->update(['password' => strtoupper(md5($password))]);
        echo 'OK';
    } else {
        echo '帳號不存在';
    }
});