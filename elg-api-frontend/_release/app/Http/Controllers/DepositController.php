<?php

namespace App\Http\Controllers;

use target\support\Helpers\CashFlows\CashFlowHelper;
use target\support\Models\AgentCashFlow;
use target\support\Services\CashFlowService;
use Illuminate\Http\Request;
use DB;

/**
 * @group Deposit
 */
class DepositController extends Controller
{
    public $rules_amount_integer =
    [
        'amount' => 'integer|min:1'
    ];


    /**
     * getOrderType
     * - 獲取支付方式列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "order_type_id": 1,
     *            "name": "支付方式名稱"
     *            "min_amount": 0, //0為不限制
     *            "max_amount": 0, //0為不限制
     *            "available_amount": "150,300,500 固額"
     *        }
     *    ]
     * }
     */
    public function getOrderType(Request $request)
    {
        try {

            $member_id = $request->ID;
            $language = $request->LANGUAGE;

            $query = DB::table('members as m')
                    ->join('agents as a', 'm.parent', 'a.id')
                    ->join('agent_cash_flows as acf', function ($join) {
                        $join->on('a.cash_flow_group_id', 'acf.agent_group_id')
                            ->where('acf.status', 1);
                    })
                    ->join('agent_cash_flow_order_types as acfot', function ($join) {
                        $join->on('acf.id', 'acfot.agent_cash_flow_id')
                            ->where('acfot.status', 1);
                    })
                    ->join('cash_flows as cf', function ($join) {
                        $join->on('acf.cash_flow_id', 'cf.id')
                            ->where('cf.status', 1)
                            ->where('cf.type', 1);
                    })
                    ->join('order_types as ot', function ($join) {
                        $join->on('acfot.order_type_id', 'ot.id')
                            ->where('ot.status', 1);
                    })
                    ->leftJoin('order_type_names as otn', function ($join) use ($language) {
                        $join->on('otn.type_id', 'ot.id')
                            ->where('otn.language_id', $language);
                    })
                    ->where('m.id', $member_id)
                    ->orderBy('acfot.sorts', 'desc')
                    ->select(
                        'ot.id as order_type_id',
                        DB::raw('(CASE WHEN otn.name is NULL THEN ot.name ELSE otn.name END) as name'),
                        'cf.id as cf_id',
                        'cf.name as cf_name',
                        'acfot.min_amount',
                        'acfot.max_amount',
                        'acfot.available_amount',
                        'acf.rate',
                        'ot.image_path'
                    )
                    ->distinct()
                    ->get();
           
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $query,
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

    /**
     * order
     * - 創建金流訂單, type: 0 失敗, 1 轉址, 2 QRCode, 3 form post
     *
     * @bodyParam order_type_id integer required 支付方式編號
     * @bodyParam amount integer required 支付金額
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "type": 1,
     *            "url": "位址"
     *        }
     *    ]
     * }
     */
    public function order(Request $request)
    {
        try {
            \DB::enableQueryLog();
            $service = new CashFlowService();

            $validator = \Validator::make($request->all(), $service->getOrderCreateRules());

            if (!$validator->passes()) {
                throw new \Exception(__('error_message.1315'), 2002);
            }
            $validator2 = \Validator::make($request->all(), $this->rules_amount_integer);
            if (!$validator2->passes()) {
                throw new \Exception(__('error_message.2026'), 2026);
            }
            $orderType = $service->getOrderType($request->ID, $request->order_type_id);
            if ($orderType === null) {
                throw new \Exception('OrderType not exists', 9995);
            }
            $agentCashFlow = AgentCashFlow::find($orderType->agent_cash_flow_id);
            if ($agentCashFlow === null) {
                throw new \Exception('AgentCashFlow not exists', 9995);
            }

            $callbackUrl = $this->getCallbackUrl($agentCashFlow->id);
            $returnUrl = $this->getReturnUrl();
            $ips = explode(',', $this->getIp());
            $ip = (is_array($ips)) ? $ips[0] : '';
            $point = $request->amount * $agentCashFlow->rate;
            $order = $service->createOrder(
                $agentCashFlow->id,
                $orderType->id,
                1,
                $request->ID,
                $request->amount,
                $point,
                $callbackUrl,
                $ip
            );
            if ($order === null) {
                throw new \Exception(__('error_message.1316'), 9999);
            }
            $helper = new CashFlowHelper($agentCashFlow->cash_flow_id);
            $data = $helper->send($agentCashFlow, $order, $callbackUrl, $returnUrl);
            if ($data['type'] === 0) {
                throw new \Exception(__('error_message.1316'), 9999);
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
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

    public function getCallbackUrl(int $agent_cash_flow_id)
    {
        return env('API_EXTERNAL_URL') . 'api/cashflow/' . $agent_cash_flow_id;
    }

    public function getReturnUrl()
    {
        $urlObj = parse_url(env('WEB_FRONTEND_URL', ''));
        if (isset($_SERVER["HTTP_REFERER"])) {
            $urlObj = parse_url($_SERVER["HTTP_REFERER"]);

        }
        return $urlObj['scheme'] . '://' . $urlObj['host'] . '/Deposit#result';
    }
}