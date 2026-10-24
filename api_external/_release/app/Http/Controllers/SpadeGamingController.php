<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\Common;
use Hash;
use DB;

class SpadeGamingController extends Controller
{

    /**
     * authenticate
     * -4.1驗證登入
     *
     * @bodyParam acctId string 遊戲帳號 Example:xxx
     * @bodyParam language string 語言 Example: xxx
     * @bodyParam merchantCode string 商戶代碼 Example: xxx
     * @bodyParam token string token Example: xxx
     * @bodyParam serialNo string 用于标识消息的序列，由调用者生成 Example: xxx
     * @response {
     *  "acctInfo": {
     *  "acctId": "TESTPLAYER1",
     *  "balance": 0,
     *  "userName": "TestPlayer",
     *  "currency": "USD"
     *  "siteId": "SITE_USD1"
     *  }
     *  "merchantCode": "SPADE"
     *  "msg": "success"
     *  "code": 0
     *  "serialNo": "20120722224255982841"
     * }
     */
    public function authenticate(Request $request)
    {
        try {
            Common::log([
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $request->all(),
                'ContentType' => $request->getContentType(),
                'Content' => $request->getContent()
            ], 'spadegaming_request_payment_body');

            if ($request->all() == null) {
                $param = json_decode($request->getContent(), true);
            } else {
                $param = $request->all();
            }

            $acctInfo = array();
            $account = DB::table('spadegaming_accounts')->where('username', $param['acctId'])->first();
            //確認有沒有此帳號
            if (is_object($account)) {
                $setting = DB::table('vendor_infos')
                    ->where('id', $account->vendor_info_id)
                    ->where('status', 1)
                    ->first();
                $info = json_decode($setting->info);

                $acctInfo['acctId'] = $param['acctId'];
                $acctInfo['balance'] = 0;
                $acctInfo['currency'] = $setting->currency;
                //確認merchantCode是否正確
                if ($param['merchantCode'] == $info->Merchant_Code) {
                    $message = 'success';
                    $code = 0;
                } else {
                    $message = 'merchantCode error';
                    $code = 2;
                }
            } else {
                $message = 'acctId is not exist';
                $code = 1;
            }
            return response()->json([
                'acctInfo' => $acctInfo,
                "merchantCode" => $param['merchantCode'],
                'msg' => $message,
                "code" => $code,
                "serialNo" => $param['serialNo']
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