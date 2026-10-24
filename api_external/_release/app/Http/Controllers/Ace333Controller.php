<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Helpers\Common;
use App\Ace333Account;
use Hash;
use DB;

class Ace333Controller extends Controller
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
            Common::log($request->all(), 'ace333_body');
            $token = [REDACTED]'token');
            Common::log($token, 'ace333_token');
            $settings = DB::table('vendor_infos')->where('vendor_id', 6)->where('status', 1)->get();
            foreach ($settings as $setting) {
                $info = json_decode($setting->info);
                if (isset($token) && $token == $info->EXTERNAL_TOKEN) {
                    $result = Ace333Account::where('username', $request->userName)
                        ->where('vendor_info_id', $setting->id)
                        ->first();
                    if (is_object($result)) {
                        if (Hash::check($request->password, $result['password'])) {
                            return response()->json([
                                'playerID' => $result['player_id'],
                                'error' => 0,
                            ]);
                        } else {
                            return response()->json([
                                'playerID' => 0,
                                'error' => 4,
                                'description' => 'Password error',
                            ]);
                        }
                    } else {
                        return response()->json([
                            'playerID' => 0,
                            'error' => 2,
                            'description' => 'User Name does not exist',
                        ]);
                    }
                }
            }
            return response()->json([
                'playerID' => 0,
                'error' => 100,
                'description' => 'Token error',
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
}