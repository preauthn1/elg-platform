<?php
namespace target\support\Helpers\CashFlows;

use Illuminate\Http\Request;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class FonPay extends CashFlowBase
{
    // 驗證POST資料
    private $rules_callback = [
        'event' => 'required|between:0,20',     //event code; DEPOSIT, SETTLE, WITHDRAW
        'coin' => 'required|between:0,20',      //coin name; USDT, TRX, DDC, FON
        'chain' => 'required|between:0,16',     //name of block chain; ETHEREUM, TRON, POLYGON, FON
        'address' => 'required|between:0,128',  //wallet address
        'recordId' => 'required',               //id
        'amount' => 'required',                 //coin amount
        'millis' => 'required',                 //UNIX time stamp in milliseconds, ex:1681291687389
        'txId' => 'required|between:0,128',     //transation id on chain
        'result' => 'nullable'                  //transaction status; SUCCESS or FAIL
    ];

    /**
     * @param AgentCashFlow $agentCashFlow
     * @param Order $order
     * @param string $callbackUrl
     * @param string $returnUrl
     * @param Request $request
     * @return array [
     *      "type" : 1  // 0失敗, 1 轉址, 2 QRCode, 3 Form Post
     *      "url" : string
     *      "html": string
     * ]
     */
    public function send(AgentCashFlow $agentCashFlow, Order $order, string $callbackUrl, string $returnUrl)
    {
        return [
            'type' => 0,
            'url' => ''
        ];
    }

    /**
     * 三方回調
     * @param Request $request
     * @return CallbackResponse
     */
    public function callback(Request $request)
    {
        try {
            $params = ($request->all() == null)
                ? json_decode($request->getContent(), true)
                : $request->all();

            $this->validate($request, $this->rules_callback);
            $this->checkOrder($params, $request->fullUrl());

            DB::beginTransaction();
            $order = Order::where('third_party_order_no', $params['recordId'])->lockForUpdate()->first();
            if ($order === null) {
                throw new Exception('can not find order', 2002);
            }

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params
            ]);

            if ($order->status == 0) {
                if (strtoupper($params['result']) == 'SUCCESS') {
                    //只留到小數第四位, 其餘無條件捨去
                    $amount = floatval(floor(floatval($params['amount'] * 10000)) / 10000);
                    if ($order->amount == $amount) {
                        $order->status = 1;
                    } else {
                        //callback金額不符
                        $order->status = 2;
                    }
                } else {
                    $order->status = 2;
                }
                $order->remark = json_encode($params);
                if (!$order->save()) {
                    throw new Exception('system error', 2002);
                }
                DB::commit();
                if ($order->status == 1) {
                    return new CallbackResponse(1, json_encode([
                        'code' => 0,
                        'message' => 'success'
                    ]), $order);
                } else {
                    return new CallbackResponse(0, json_encode([
                        'code' => 10001,
                        'message' => 'order status not success'
                    ]), null);
                }
            } else {
                throw new Exception('order status not 0', 2002);
            }
        } catch (QueryException $exception) {
            DB::rollback();
            $info = $exception->errorInfo;
            Log::error(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'exception' => $exception->getMessage()
            ]);
            return new CallbackResponse(0, json_encode([
                'code' => 10001,
                'message' => 'QueryException failure'
            ]), null);
        } catch (Exception $exception) {
            DB::rollback();
            Log::error(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'exception' => $exception->getMessage()
            ]);
            return new CallbackResponse(0, json_encode([
                'code' => 10001,
                'message' => 'Exception failure'
            ]), null);
        }
    }

    private function checkOrder($params, $full_url)
    {
        $agent_cash_flow_id = $params['agent_cash_flow_id'];
        $order = Order::where('third_party_order_no', $params['recordId'])
            ->where('agent_cash_flow_id', $agent_cash_flow_id)
            ->first();
        if (!is_object($order)) {
            //建立訂單
            $orderType = DB::table('agent_cash_flows as acf')
                ->join('agent_cash_flow_order_types as acfot', 'acfot.agent_cash_flow_id', 'acf.id')
                ->join('order_types as ot', function ($join) use ($params) {
                    $join->on('ot.id', 'acfot.order_type_id')
                        ->where('ot.key', $params['coin'] . '-' . $params['chain']);
                })
                ->where('acf.id', $agent_cash_flow_id)
                ->select(
                    'acf.rate',
                    'ot.id as order_type_id',
                    'acfot.id as acfot_id'
                )
                ->first();

            if (is_object($orderType)) {
                $member_id = $this->getMemberByAddress($orderType->acfot_id, $params['address']);
                $amount = floatval(floor(floatval($params['amount'] * 10000)) / 10000);
                $data = [
                    'agent_cash_flow_id' => $agent_cash_flow_id,
                    'order_type_id' => $orderType->order_type_id,
                    'currency_id' => 1,
                    'member_id' => $member_id,
                    'amount' => $amount,
                    'point' => floatval($amount / $orderType->rate),
                    'balance' => 0,
                    'callback' => $full_url,
                    'status' => 0,
                    'ip' => '',
                    'third_party_order_no' => $params['recordId']
                ];

                $try = 0;
                do {
                    $data['order_no'] = $this->getOrderNo();
                    $model = Order::create($data);
                    $try++;
                } while (!$model && $try < 3); // 最多嘗試三次創建訂單
            }
        }
    }

    public function getOrderNo()
    {
        $prefix = date('Ymd');
        $orderNo = $prefix
            .random_int(100000, 999999)
            .substr(sprintf("%01.4f", microtime(true)), -4);
        return $orderNo;
    }

    private function getMemberByAddress($acfot_id, $address)
    {
        $member_id = 0;
        $memberCryptoWallet = DB::table('member_crypto_wallets')
            ->where('agent_cash_flow_order_type_id', $acfot_id)
            ->where('address', $address)
            ->first();
        if (is_object($memberCryptoWallet)) {
            $member_id = $memberCryptoWallet->member_id;
        }
        return $member_id;
    }
}
