<?php

namespace App\Http\Controllers\External\TransferWallets;

use App\Http\Controllers\Controller;
use App\Vendors\Models\Mega888Account;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class Mega888Controller extends Controller
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
    public function login(Request $request)
    {
        try {
            $contents = file_get_contents('php://input');
            Log::info('mega888_body', ['contents' => $contents]);
            Log::info('mega888_header', $request->header());

            $json = str_replace('json=', '', $contents);
            $request_array = json_decode($json, true);
            $sessionId = Session::getId();

            $responseData = [
                'id' => $request_array['id'],
                'result' => [
                    'success' => '1',
                    'sessionId' => $sessionId,
                    'msg' => '登錄成功',
                ],
                'error' => null,
                'jsonrpc' => '2.0',
            ];

            if ($request_array['method'] !== 'open.operator.user.login') {
                $responseData['result']['success'] = '0';
                $responseData['result']['msg'] = 'Method錯誤';

                return response()->json($responseData);
            }

            // 查詢帳號是否存在
            $account = Mega888Account::where('player_id', $request_array['params']['loginId'])->first();
            if (!is_object($account)) {
                $responseData['result']['success'] = '0';
                $responseData['result']['msg'] = '帳號不存在';

                return response()->json($responseData);
            }

            if (!Hash::check($request_array['params']['password'], $account->password)) {
                $responseData['result']['success'] = '0';
                $responseData['result']['msg'] = '密碼錯誤';

                return response()->json($responseData);
            }

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
