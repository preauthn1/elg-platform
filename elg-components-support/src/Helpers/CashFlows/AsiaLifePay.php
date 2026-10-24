<?php
namespace target\support\Helpers\CashFlows;

use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use target\support\Helpers\Utils\AES128;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AsiaLifePay extends CashFlowBase
{
    // 回調參數策略
    private $rules_callback = [
        'special_station_no' => 'required',
        'collection_amount' => 'required',
        'collection_no' => 'required',
        'collection_order_no' => 'required',
        'token' => 'required'
    ];

    /**
     * @param AgentCashFlow $agentCashFlow
     * @param Order $order
     * @param string $callbackUrl
     * @param string $returnUrl
     * @return array [
     *      "type" : 1  // 0失敗, 1 轉址, 2 QRCode , 11: 前端將code轉成QRCode
     *      "url" : string,
     *      'code' : "要請前端處理的代碼"
     * ]
     */
    public function send(AgentCashFlow $agentCashFlow, Order $order, string $callbackUrl, string $returnUrl)
    {
        $info = json_decode($agentCashFlow->info, true);

        // D1 特約夥伴代
        // D2 收款種類
        // D3 金額
        // D4 代收種類代
        // D5 銷帳編號
        // D6 交易驗證碼
        // D7 繳費期限
        // D8 繳費期限逾期後是否失效
        if (!array_key_exists("vendor_id", $info) || !array_key_exists("pay_type_code", $info)) {
            throw new Exception('info setting not completed', 2002);
        }

        //產生 yyMMdd + elg_order訂單號 的16碼 訂單號
        $ymd = ((int)substr(date("Ymd", strtotime($order->created_at)), 2, 6));
        $order_no_16 = $ymd * 10000000000 + $order->id;

        $p_D1 = $info["vendor_id"]; //5003
        $p_D2 = "1";
        $p_D3 = (int)$order->amount;
        $p_D4 = $info["pay_type_code"]; //500304
        $p_D5 = $order_no_16;
        $p_D6 = "sign";

        $now = date("Y-m-d H:i:s");
        $expired_time = date("YmdHis", strtotime('+48 hours', strtotime($now)));

        $p_D7 = $expired_time;
        $p_D8 = "1";
        $p_D10 = "2";
        $p_D15 = "2";

        $sign_P1 = $this->calculateCheckCodeP1($p_D1);
        $sign_P2 = $this->calculateCheckCodeP2($p_D5);
        $sign_P3 = $this->calculateCheckCodeP3($p_D3);

        //檢查碼
        $p_D6 = $sign_P1 * $sign_P2 * $sign_P3;

        $check_code = $sign_P1 . "_" . $sign_P2 . "_" . $sign_P3;

        $transaction_code = sprintf(
            "AsiaLife://D1=%s&D2=%s&D3=%s&D4=%s&D5=%s&D6=%s&D7=%s&D8=%s&D10=%s&D15=%s",
            $p_D1,
            $p_D2,
            $p_D3,
            $p_D4,
            $p_D5,
            $p_D6,
            $p_D7,
            $p_D8,
            $p_D10,
            $p_D15
        );

        $my_debug_info = array();
        $my_debug_info["check_code"] = $check_code;
        $my_debug_info["acf_id"] = $agentCashFlow->id;
        $my_debug_info["before_code"] = $transaction_code;

        //v1.0.3版本 不需要進行加密
        if ($info["mode"] == "v3") {
            return ['type' => 11, 'url' => '', 'code' =>  $transaction_code, 'debug_info' => $my_debug_info];
        }

        $AES_result = $this->encryptByAES128($transaction_code, $info);
        $AES_result = base64_encode($AES_result);

        //v1.0.4版本後，要組出加密字串
        $final_Code =  sprintf("AsiaLife://D1=%s&code=%s", $p_D1, $AES_result);


        return ['type' => 11, 'url' => '', 'code' => $final_Code, 'debug_info' => $my_debug_info];
    }


    private function encryptByAES128($before_AES_text, $db_setting_info)
    {
        $AES = new AES128($db_setting_info["AES_Key"], $db_setting_info["AES_iv"]);
        $after_AES = $AES->encrypt($before_AES_text);
        return $after_AES;
    }


    /**
     * 三方回調
     * @param Request $request
     * @return CallbackResponse
     */
    public function callback(Request $request)
    {
        $params = $request->all();
        DB::beginTransaction();
        try {
            $this->validate($request, $this->rules_callback);

            //單號的前六碼是純粹的時間流水號 後10碼是orderID (補0)  範例: 2101260000000909  909是orderID
            $order_id = (int)substr($params['collection_no'], 6, 10);
          
            $order = Order::where('id', $order_id)->lockForUpdate()->first();

            if ($order === null) {
                throw new Exception('can not find order', 2002);
            }

            if ($order->member_id > 0) {
                $agentCashFlow = DB::table("members as m")
                    ->leftJoin("agents as a", "m.parent", "a.id")
                    ->leftJoin("agent_cash_flows as acf", "a.cash_flow_group_id", "acf.agent_group_id")
                    ->where("m.id", $order->member_id)
                    ->where("acf.cash_flow_id", 12)
                    ->select("acf.*")
                    ->first();
            } else {
                $agentCashFlow = DB::table("agents as a")
                    ->leftJoin("agent_cash_flows as acf", "a.cash_flow_group_id", "acf.agent_group_id")
                    ->where("a.id", $order->agent_id)
                    ->where("acf.cash_flow_id", 12)
                    ->select("acf.*")
                    ->first();
            }

            $info = json_decode($agentCashFlow->info, true);

            $this->checkSign($info, $request, $params);

            Log::debug(
                get_class($this) . '_callback',
                [
                    'method' => $_SERVER['REQUEST_METHOD'],
                    'url' => $request->fullUrl(),
                    'params' => $params
                ]
            );

            if ($order->status == 0) {
                $amount = $this->getAmount($params["collection_amount"]);
                if ($order->amount == $amount) {
                    $order->status = 1;
                } else {
                    //callback金額不符
                    $order->status = 2;
                }

                //有通知就算成功
                $order->third_party_order_no = $params["collection_order_no"]; //三方單號

                $order->remark = json_encode(['content' => $params]);

                if (!$order->save()) {
                    throw new Exception('system error', 2002);
                }
                DB::commit();
                if ($order->status == 1) {
                    return new CallbackResponse(1, "OK", $order);
                } else {
                    return new CallbackResponse(0, "failure", null);
                }
            } else {
                throw new Exception('order status not 0', 2002);
            }
        } catch (Exception $exception) {
            DB::rollback();
            Log::error(
                get_class($this) . '_callback',
                [
                    'method' => $_SERVER['REQUEST_METHOD'],
                    'url' => $request->fullUrl(),
                    'params' => $params,
                    'exception' => $exception->getMessage()
                ]
            );
            return new CallbackResponse(0, "failure", null);
        }
    }

    private function makeSign($info, $params)
    {
        $concateText = sprintf(
            "%s%s%s%s%s%s%s%s%s",
            $params["special_station_no"],
            $params["special_collection_type_no"],
            $params["collection_station_no"],
            $params["collection_type"],
            $params["collection_amount"],
            $params["collection_no"],
            $params["collection_at"],
            $params["collection_order_no"],
            $params["special_collection_service_fee"]
        );

        $token = [REDACTED]"token"];
        $VendorName = "AsiaLife";
        $nowYear = date("Y");
        $beforeSHA256 = $token . $concateText . $nowYear . $VendorName;

        $result = hash('sha256', $beforeSHA256);
        return $result;
    }

    /**
     * @param $info
     * @param $request
     * @param $content
     * @param $params
     * @throws Exception
     */
    private function checkSign($info, $request, $params)
    {
        $sign = $this->makeSign($info, $params);

        if (strtoupper($sign) != strtoupper($request->token)) {
            throw new Exception('sign error', 2002);
        }
    }

    private function calculateCheckCodeP1($D1_vendorID)
    {
        $chars = str_split($D1_vendorID);
        $weighted_rate = 1; //加權指數
        $totalSum = 0;

        foreach ($chars as $char) {
            $parseInt = (int)$char;
            $totalSum = $totalSum + $parseInt * $weighted_rate;
            $weighted_rate++;
        }

        return $totalSum;
    }

    private function calculateCheckCodeP2($D5_transactionNo)
    {
        $totalSum = 0;

        $charsOfOrderNo = str_split($D5_transactionNo);
        $weighted_rate_P2 = 16; //加權指數
        foreach ($charsOfOrderNo as $char) {
            $parseInt = (int)$char;
            $totalSum = $totalSum + $parseInt * $weighted_rate_P2;
            $weighted_rate_P2--;
        }

        return $totalSum;
    }

    private function calculateCheckCodeP3($D3_amount)
    {
        $totalSum = 0;
        $amount = (int)$D3_amount;

        //將金額轉成6位數 前面補0
        $amountPadLeft = str_pad($amount, 6, "0", STR_PAD_LEFT);

        $charsOfAmountText = str_split($amountPadLeft);
        $weighted_rate_P3 = 1; //加權指數
        

        foreach ($charsOfAmountText as $char) {
            $parseInt = (int)$char;
            $totalSum = $totalSum + $parseInt * $weighted_rate_P3;
            $weighted_rate_P3++;
        }

        return $totalSum;
    }
}
