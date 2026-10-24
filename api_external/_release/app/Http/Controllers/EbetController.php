<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Helpers\Common;
use App\EbetAccount;
use Hash;
use UUID;
use Session;
use DB;

class EbetController extends Controller
{

    /**
     * authenticate
     * -4.1驗證登入
     *
     * @bodyParam cmd string 執行動作 Example:RegisterOrLoginReq
     * @bodyParam eventType integer 值为4－Token登入 Example: 4
     * @bodyParam channelId integer 渠道編號 Example: 0000
     * @bodyParam username string 玩家登入帳號 Example:test
     * @bodyParam accesssToken string 遊戲URL帶的token Example:ajsdlfknwd
     * @bodyParam passowrd string 玩家登入密碼 Example:123456
     * @bodyParam signature string 簽名
     * @bodyParam timestamp long 當前時間(unix time)
     * @bodyParam ip string 玩家ip
     * @response {
     *  "status": "成功回應200，4026 － 验证失败 4027 － IP 无访问权限 401 － 用户名或是密码不对 410 － 无效的 token 505 － 渠道服务器在维护中",
     *  "subChannelId": "来自指渠道管理员创建的子渠道ID，没有就填0",
     *  "accessToken": "遊戲URL帶的token",
     *  "username": "玩家登入帳號",
     *  "currency": "用戶使用的货币",
     *  "nickname": "昵称。如果不带入此参数，ebet server默认为随机"
     * }
     */
    public function authenticate(Request $request)
    {
        try {
            Common::log($request->header(), 'ebet_header');
            Common::log($request->all(), 'ebet_request');
            $currency = 'MYR';
            //{"cmd":"RegisterOrLoginReq","eventType":4,"channelId":1141,"username":"dev00000003","accessToken":"01302a6cd5370c0404b2a178040bdc94","password":null,"timestamp":1574918620,"ip":"0.0.0.0","signature":"UQL3gMP15oihOxyvdMeViVZiySWSVUBKdHmfQE5+5B\/ZdkyyBFVNSj1bGIxHtK\/B8HZtLnH+\/d3NyA0Y4OMS+A=="}
            //驗證執行動作是否為登入
            if ($request->cmd == 'RegisterOrLoginReq' && $request->eventType == 4) {
                //查詢帳號是否存在
                $result = EbetAccount::where('username', $request->username)->first();
                $info = DB::table('vendor_infos')->where('id', $result->vendor_info_id)->first();
                $currency = is_object($info) ? $info->currency : 'MYR';
                if (is_object($result)) {
                    $password = [REDACTED], $result->updated_at);
                    //驗證密碼是否正確
                    if ($request->accessToken == md5($password)) {
                        //正確後修改account最後更新時間，讓下次的token可以不一樣
                        $result->update(['updated_at' => date('Y-m-d H:i:s')]);
                        $status = 200;
                        $message = '登錄成功';
                    } else {
                        $status = 410;
                        $message = 'Access token錯誤';
                    }
                } else {
                    $status = 401;
                    $message = '帳號不存在';
                }
            } else {
                $status = 4026;
                $message = 'Mehtod錯誤';
            }
            return response()->json([
                'status' => $status,
                'subChannelId' => 0,
                'accessToken' => $request->accessToken,
                'username' => $request->username,
                'currency' => $currency,
            ]);
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

    private function getVendorPassword($username, $createTime)
    {
        return substr(md5($username . strtotime($createTime)), 8, 12);
    }
}