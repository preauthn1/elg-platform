<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Helpers\Common;
use App\Ace333Account;
use Hash;
use DB;

class FishingController extends Controller
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
            Common::log($request->all(), 'fishing_body');
            $setting = DB::table('vendor_infos')->where('vendor_id', 39)->where('status', 1)->first();
            $info = json_decode($setting->info);
            //把需串成sign的參數放進array
            $params = array(
                "account" => $request->account,
                "token" => $request->token,
                "time" => $request->time,
            );

            $sign = $this->sign($params, $info->KEY);

            if ($request->sign != $sign) {
                return response()->json([
                    'code' => 1,
                    'msg' => 'sign error',
                ]);
            }
            $token = [REDACTED]'fishing_accounts as fa')
                ->join('member_access_tokens as mat', 'mat.member_id', 'fa.member_id')
                ->where('fa.username', $request->account)
                ->orderBy('mat.id', 'DESC')
                ->pluck('mat.token')
                ->first();
            //如果查不到代表帳號不存在
            if (isset($token)) {
                //檢查token
                if (!isset($request->token) || $request->token != $token) {
                    return response()->json([
                        'code' => 3,
                        'msg' => 'token error',
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 2,
                    'msg' => 'account is not exist',
                ]);
            }
            $balance = DB::table('members as m')
                ->join('fishing_accounts as fa', 'fa.member_id', 'm.id')
                ->where('fa.username', $request->account)
                ->pluck('m.balance')
                ->first();
            //如果查不到代表帳號不存在
            if (isset($balance)) {
                return response()->json([
                    'code' => 0,
                    'msg' => 'pass',
                    'account' => $request->account,
                    'nick' => $request->account,
                    'icon' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/161552148291.jpeg',
                    'wealth' => $balance
                ]);
            } else {
                return response()->json([
                    'code' => 2,
                    'msg' => 'account is not exist',
                ]);
            }
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

    private function sign($params, $key)
    {
        ksort($params);
        $str = http_build_query($params) . "&key=$key";
        return md5($str);
    }
}