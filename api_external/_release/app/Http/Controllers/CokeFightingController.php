<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\CokeFightingAccount;
use Validator;

class CokeFightingController extends Controller
{

    /**
     * GetBalance
     * -取餘額
     *
     * @bodyParam action string 動作 Example:getBalance
     * @bodyParam userId string 帳號 Example: xxx
     * @bodyParam cert string 證書 Example: xxx
     * @response {
     *  "status": "200:成功，500:失敗",
     *  "balance": "成功回應餘額",
     *  "desc": "失敗回應錯誤訊息"
     * }
     */
    public function getBalance(Request $request)
    {
        try {
            // 驗證POST資料
            if (!isset($request->action) || $request->action != 'getBalance') {
                return response()->json([
                    'code' => 500,
                    'desc' => 'Action does not exist',
                ]);
            }
            if (!isset($request->userId) || $request->userId == '') {
                return response()->json([
                    'code' => 500,
                    'desc' => 'User id can not be null',
                ]);
            }

            if (!isset($request->cert) || $request->cert != env('COKE_FIGHTING_CERT')) {
                return response()->json([
                    'code' => 500,
                    'desc' => 'Cert error',
                ]);
            }

            $result = CokeFightingAccount::where('username', $request->userId)->first();
            if (is_object($result)) {
                return response()->json([
                    'code' => 200,
                    'balance' => $result['balance'],
                ]);
            } else {
                return response()->json([
                    'code' => 500,
                    'desc' => 'User id does not exist',
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
}