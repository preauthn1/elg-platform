<?php

use Illuminate\Http\Request;

/*
 * |--------------------------------------------------------------------------
 * | API Routes
 * |--------------------------------------------------------------------------
 * |
 * | Here is where you can register API routes for your application. These
 * | routes are loaded by the RouteServiceProvider within a group which
 * | is assigned the "api" middleware group. Enjoy building your API!
 * |
 */

use App\Helpers\Common;

use function GuzzleHttp\json_encode;

// TODO 上線要刪除
Route::post('/encrypt', function (Request $request) {
    $params = Common::encrypt(env('API_AES_KEY'), env('API_IV'), json_encode($request->all()));
    $array = array();
    $array['params'] = $params;
    $array['key'] = md5($params . env('API_MD5_KEY'));
    $array['sign'] = md5($request->API_USER . env('API_MD5_KEY') . $request->API_KEY . env('API_IV') . $request->TIMESTAMP . env('API_AES_KEY') . $request->THIRD_PARTY_ID);
    $array['before_sign'] = $request->API_USER . '~~~~~~~~~~~~' . env('API_MD5_KEY') . '~~~~~~~~~~~~' . $request->API_KEY . '~~~~~~~~~~~~' . env('API_IV') . '~~~~~~~~~~~~' . $request->TIMESTAMP . '~~~~~~~~~~~~' . env('API_AES_KEY') . '~~~~~~~~~~~~' . $request->THIRD_PARTY_ID;
    echo json_encode($array);
});
// TODO 上線要刪除
Route::get('/redis', function () {
    var_dump(Redis::keys('*'));
    $keys = Redis::keys('*');
    foreach ($keys as $key) {
        \Redis::del($key);
    }
    exit();
});

//臨時用來同步帳號，沒加密，只有限制ip
Route::post('synchronize', 'UserController@sync');
Route::post('agentUnlimitBonus', 'UserController@userUnlimitBonus');

// 不用登入即可使用的API
Route::group([
    'middleware' => 'none_auth'
], function () {
    /* 客服後台登入 */
    Route::post('login', 'Auth\LoginController@login');
    /* 語系列表 */
    Route::post('languages', 'LanguageController@list');
});

/* 登入後才可使用的API(驗證TOKEN) */
Route::group([
    'middleware' => 'auth'
], function () {
    /* 營運商/客服列表 */
    Route::post('user/list', 'UserController@list');
    /* 管理者/營運商/客服詳細資料 */
    Route::post('user/view', 'UserController@show');
    /* 建立營運商/客服 */
    Route::post('user/add', 'UserController@create');
    /* 編輯營運商/客服 */
    Route::post('user/edit', 'UserController@update');
    /* 凍結營運商/客服 */
    Route::post('user/delete', 'UserController@delete');
    /* 修改自己密碼 */
    Route::post('user/changePasswordBySelf', 'UserController@changePasswordBySelf');
    /* 修改營運商/客服密碼 */
    Route::post('user/changePassword', 'UserController@changePassword');
    /* 代理之間開洗分 */
    Route::post('user/transaction', 'UserController@userTransaction');

    /* 後台選單列表 */
    Route::post('userMenu/list', 'UserMenuController@list');
    /* 驗證後台選單權限 */
    Route::post('userMenu/auth', 'UserMenuController@auth');

    /* 系統設定詳情 */
    Route::post('setting/view', 'SettingController@show');

    /* 系統設定儲存 */
    Route::post('setting/save', 'SettingController@save');

    /* 開分/洗分列表 */
    Route::post('transaction/list', 'TransactionController@list');
    /* 開分/洗分 */
    Route::post('transaction/add', 'TransactionController@create');
    /* 編輯開分/洗分記錄 */
    Route::post('transaction/edit', 'TransactionController@update');

    /* 紅包列表(客服用) */
    Route::post('redEnvelope/record', 'RedEnvelopeController@record');

    /* 報表sr02 */
    Route::post('report/sr02', 'ReportController@sr02');

    /* 客服後台登出 */
    Route::post('logout', 'Auth\LoginController@logout');
});

// 聊天室用
Route::group([
    'middleware' => 'chat'
], function () {
    /* 帳號查詢 */
    Route::post('member/search', 'MemberController@show');
    /* 會員帳號資訊 */
    Route::post('member/view', 'MemberController@view');
    /* 會員本人帳號資訊 */
    Route::post('member/self', 'MemberController@self');
    /* 編輯自己的帳號 */
    Route::post('member/edit', 'MemberController@update');
    /* 稽核流水 */
	Route::post('member/flowLimit', 'MemberController@getFlowLimit');

    /* 好友列表 */
    Route::post('friend/list', 'FriendController@list');
    /* 好友資料 */
    Route::post('friend/view', 'FriendController@show');
    /* 新增好友 */
    Route::post('friend/add', 'FriendController@create');
    /* 編輯好友 */
    Route::post('friend/edit', 'FriendController@update');
    /* 刪除好友 */
    Route::post('friend/delete', 'FriendController@delete');
    // TODO 未讀
    /* 聊天室列表 */
    Route::post('room/list', 'ChatController@list');
    /* 進入聊天室 */
    Route::post('room/enter', 'ChatController@enter');
    /* 聊天室歷史記錄 */
    Route::post('room/history', 'ChatController@history');
    /* 聊天室修改狀態 */
    Route::post('room/changeStatus', 'ChatController@changeStatus');
    /* 記錄標記為已讀 */
    Route::post('readMessage', 'ChatController@read');
    /* 發送訊息 */
    Route::post('sendMessage', 'ChatController@send');

    /* 記事列表 */
    Route::post('note/list', 'NoteController@list');
    /* 新增記事 */
    Route::post('note/add', 'NoteController@create');
    /* 編輯記事 */
    Route::post('note/edit', 'NoteController@update');
    /* 記事內容列表 */
    Route::post('note_content/list', 'NoteController@contentList');
    /* 新增記事內容 */
    Route::post('note_content/add', 'NoteController@contentCreate');
    /* 編輯記事內容 */
    Route::post('note_content/edit', 'NoteController@contentUpdate');
    /* 刪除記事 */
    Route::post('note_content/delete', 'NoteController@contentDelete');

    /* 紅包列表 */
    Route::post('redEnvelope/list', 'RedEnvelopeController@list');
    /* 紅包明細 */
    Route::post('redEnvelope/view', 'RedEnvelopeController@show');
    /* 新增紅包 */
    Route::post('redEnvelope/add', 'RedEnvelopeController@create');
    /* 驗證密碼 */
    Route::post('redEnvelope/validatePassword', 'RedEnvelopeController@validatePassword');
    /* 打開紅包 */
    Route::post('redEnvelope/edit', 'RedEnvelopeController@update');
    /* 取消紅包 */
    Route::post('redEnvelope/cancel', 'RedEnvelopeController@cancel');

    /* 洗分確認 */
    Route::post('transaction/confirm', 'TransactionController@confirm');
});

Route::group([
    'middleware' => 'third_party_auth'
], function () {
    Route::post('third_party_login', 'Auth\LoginController@login');
});