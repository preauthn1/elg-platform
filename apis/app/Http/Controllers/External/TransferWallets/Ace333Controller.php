<?php

namespace App\Http\Controllers\External\TransferWallets;

use App\Http\Controllers\Controller;
use App\Vendors\Models\Ace333Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Ace333Controller extends Controller
{
    /**
     * authenticate
     * -4.1驗證登入
     *
     * @bodyParam token string token Example:xxx
     * @bodyParam userName string 帳號 Example: xxx
     * @bodyParam password string 密碼 Example: xxx
     *
     * @response {
     *  "playerID": "玩家編號",
     *  "error": "成功回應0，其他錯誤代碼自訂",
     *  "description": "失敗回應錯誤訊息(選填)"
     * }
     */
    public function authenticate(Request $request)
    {
        try {
            Log::info('ace333_body', $request->all());

            $token = [REDACTED]'token');

            Log::info('ace333_token', ['token' => $token]);

            $account = Ace333Account::where('username', $request->userName)->first();
            if (!is_object($account)) {
                return response()->json([
                    'playerID' => 0,
                    'error' => 2,
                    'description' => 'User Name does not exist',
                ]);
            }

            $vendorInfo = $account->vendorInfo()->where('status', 1)->first();
            if (!is_object($vendorInfo)) {
                return response()->json([
                    'playerID' => 0,
                    'error' => 100,
                    'description' => 'Token error',
                ]);
            }

            $info = json_decode($vendorInfo->info);
            if (isset($token) && $token !== $info->EXTERNAL_TOKEN) {
                return response()->json([
                    'playerID' => 0,
                    'error' => 100,
                    'description' => 'Token error',
                ]);
            }

            if ($request->password !== $info->H5_PASSWORD) {
                return response()->json([
                    'playerID' => 0,
                    'error' => 4,
                    'description' => 'Password error',
                ]);
            }

            return response()->json([
                'playerID' => $account->player_id,
                'error' => 0,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;

            return response()->json([
                'code' => $info[0],
                'message' => $info[2],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
