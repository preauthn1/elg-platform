<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Helpers\Common;
use App\Mega888Account;
use Hash;
use UUID;
use Session;

class Mega888Controller extends Controller
{

    /**
     * authenticate
     * -4.1驗證登入
     *
     * @bodyParam token string token Example:xxx
     * @bodyParam userName string 帳號 Example: xxx
     * @bodyParam password string 密碼 Example: xxx
     * @response {
     *  "playerID": "玩家編號",
     *  "error": "成功回應0，其他錯誤代碼自訂",
     *  "description": "失敗回應錯誤訊息(選填)"
     * }
     */
    public function authenticate(Request $request)
    {
        try {
            Common::log(file_get_contents('php://input'), 'mega888_body');
            Common::log($request->header(), 'mega888_header');
            // var_dump($request);
            $json = str_replace('json=', '', file_get_contents('php://input'));
            $request_array = json_decode($json, true);
            //驗證執行動作是否為登入
            if ($request_array['method'] == 'open.operator.user.login') {
                //查詢帳號是否存在
                $result = Mega888Account::where('player_id', $request_array['params']['loginId'])->first();
                if (is_object($result)) {
                    // $password = [REDACTED], $result->created_at);
                    //驗證密碼是否正確
                    if (Hash::check($request_array['params']['password'], $result['password'])) {
                        // if ($request_array['params']['password'] == $password) {
                        $is_success = 1;
                        $message = '登錄成功';
                    } else {
                        $is_success = 0;
                        $message = '密碼錯誤';
                    }
                } else {
                    $is_success = 0;
                    $message = '帳號不存在';
                }
            } else {
                $is_success = 0;
                $message = 'Mehtod錯誤';
            }

            return response()->json([
                'id' => $request_array['id'],
                'result' => [
                    "success" => (string) $is_success,
                    "sessionId" => Session::getId(),
                    "msg" => $message
                ],
                'error' => null,
                'jsonrpc' => "2.0",
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