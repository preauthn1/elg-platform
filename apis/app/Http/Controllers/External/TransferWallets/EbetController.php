<?php

namespace App\Http\Controllers\External\TransferWallets;

use App\Http\Controllers\Controller;
use App\Vendors\Models\EbetAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
     *
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
            Log::info('ebet_header', $request->header());
            Log::info('ebet_request', $request->all());

            $currency = 'MYR';

            $responseData = [
                'status' => 200,
                'subChannelId' => 0,
                'accessToken' => $request->accessToken,
                'username' => $request->username,
                'currency' => $currency,
            ];

            if ($request->cmd != 'RegisterOrLoginReq' || $request->eventType != 4) {
                $responseData['status'] = 4026; // Method

                return response()->json($responseData);
            }

            $account = EbetAccount::where('username', $request->username)->first();
            if (!is_object($account)) {
                $responseData['status'] = 401; // Account not found

                return response()->json($responseData);
            }

            $vendorInfo = $account->vendorInfo()->where('status', 1)->first();
            $currency = is_object($vendorInfo) ? $vendorInfo->currency : 'MYR';
            $password = [REDACTED] . $account->updated_at->timestamp), 8, 12);

            if ($request->accessToken != md5($password)) {
                $responseData['status'] = 410; // Access token error

                return response()->json($responseData);
            }

            $account->update(['updated_at' => date('Y-m-d H:i:s')]);

            return response()->json($responseData);
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
