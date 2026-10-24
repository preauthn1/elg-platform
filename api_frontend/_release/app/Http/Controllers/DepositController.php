<?php

namespace App\Http\Controllers;

use App\Enums\AgentCustomizedSettingKeyEnum;
use target\support\Helpers\CashFlows\CashFlowHelper;
use target\support\Models\AgentCashFlow;
use target\support\Services\CashFlowService;
use Illuminate\Http\Request;
use App\Models\Order;
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
            $result = array();
            $agent = DB::table('agents as a')
                ->join('members as m', function ($join) use ($member_id) {
                    $join->on('m.parent', 'a.id')
                        ->where('m.id', $member_id);
                })
                ->first();
            $a = $request->LANGUAGE;
            //deposit
            if ($this->checkAutoDeposit($agent)) {
                $name = $this->get_target_language_settingText(
                    $agent->customized_setting_group_id,
                    $request->LANGUAGE,
                    AgentCustomizedSettingKeyEnum::LABEL_TEXT_AUTO_DEPOSIT
                );
                //圖片先做寫死,有需要更改再放進DB
                $autoDeposit = array(
                    'order_type_id' => 0,
                    'name' => $name,
                    'cf_id' => 5,
                    'cf_name' => 'AutoDeposit',
                    'min_amount' => 0,
                    'max_amount' => 0,
                    'rate' => 0,
                    'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/topup.png',
                    'order_type_info' => ''
                );
                $result[] = $autoDeposit;
            }


            //withdrawal
            if ($this->checkWithdrawal($agent)) {
                $name = $this->get_target_language_settingText(
                    $agent->customized_setting_group_id,
                    $request->LANGUAGE,
                    AgentCustomizedSettingKeyEnum::LABEL_TEXT_WITHDRAWAL
                );
                //圖片先做寫死,有需要更改再放進DB
                $withdrawal = array(
                    'order_type_id' => 0,
                    'name' => $name,
                    'cf_id' => 6,
                    'cf_name' => 'Withdrawal',
                    'min_amount' => 0,
                    'max_amount' => 0,
                    'rate' => 0,
                    'image_path' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/withdrawal.png',
                    'order_type_info' => ''
                );
                $result[] = $withdrawal;
            }

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
                        ->where(function ($join) {
                            $join->where('cf.type', 1);
                            $join->orwhere('cf.id', 18);
                        });
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
                    'ot.image_path',
                    'example.invalid as order_type_info'
                )
                ->distinct()
                ->get();


            foreach ($query as $row) {
                //如果是18預付網的話,要直接另開所以要加上URL
                if ($row->cf_id == 18) {
                    $AgentCashFlow = DB::table('agent_cash_flows as acf')
                        ->join('agent_groups as ag', 'ag.id', 'acf.agent_group_id')
                        ->join('agents as a', function ($join) use ($request) {
                            $join->on('a.cash_flow_group_id', 'ag.id')
                                ->where('a.id', $request->MY_PARENT_AGENT);
                        })
                        ->where('acf.cash_flow_id', 18)
                        ->select('acf.*')
                        ->first();

                    if (is_object($AgentCashFlow)) {
                        $info = json_decode($AgentCashFlow->info);
                        $url = $info->api_url . 'code=' . $info->referral_code . '&uid=' . $request->TOKEN;
                    } else {
                        $url = '';
                    }
                    $row->url = $url;
                }

                $json = json_decode($row->order_type_info);
                if (is_object($json) && isset($json->data)) {
                    foreach($json->data as $r) {
                        $r->placeholder = __('param.placeholder.' . $r->display);
                        $r->display = __('param.display.' . $r->display);
                    }
                }
                $row->order_type_info = ($json) ? $json->data : $json;
                $result[] = $row;
            }
           
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $result
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
            //鎖分模式中禁止領取獎勵
            if ($this->checkMemberEventLockByMemberId($request->ID) > 0) {
                return response()->json([
                    'code' => 1934,
                    'message' => __('error_message.1934')
                ]);
            }
            
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
            $amount = $request->amount?$request->amount:"";
            $callbackUrl = $this->getCallbackUrl($agentCashFlow->id);
            $returnUrl = $this->getReturnUrl($request->ID);
            $ips = explode(',', $this->getIp());
            $ip = (is_array($ips)) ? $ips[0] : '';
            $point = $request->amount * $agentCashFlow->rate;
            $order = $service->createOrder(
                $agentCashFlow->id,
                $orderType->id,
                1,
                $request->ID,
                $amount,
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

            //依返回code, message做response
            if ($data['type'] === 9) {
                return response()->json([
                    'code' => $data['code'],
                    'message' => $data['message']
                ]);
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

    public function depositResultDomain(Request $request)
    {
        try {
            $order = Order::where('order_no', $request->item_number)->first();
            $member_id = (is_object($order)) ? $order->member_id : 0;
            $domain =  $this->getReturnUrl($member_id, 1);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'domain' => $domain
                ]
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

    public function getReturnUrl($member_id, $type = 0)
    {
        $member = DB::table('members as m')
            ->join('agents as a', 'a.id', 'm.parent')
            ->where('m.id', $member_id)
            ->select(
                'a.hierarchy_path'
            )
            ->first();
        if (is_object($member)) {
            $agent_ids = explode('/', $member->hierarchy_path);

            $domain = DB::table('agent_domains as ad')
                ->join('agents as a', function ($join) use ($agent_ids) {
                    $join->on('a.id', 'ad.agent_id')
                        ->whereIn('a.id', $agent_ids);
                })
                ->where('ad.status', 1)
                ->where('ad.type', 0)
                ->orderBy('level', 'DESC')
                ->orderBy('ad.sort', 'DESC')
                ->orderBy('ad.id', 'ASC')
                ->select(
                    'a.id as agent_id',
                    'ad.domain',
                    DB::raw("ROUND(LENGTH(a.hierarchy_path) - LENGTH(REPLACE(a.hierarchy_path, '/', ''))) AS level")
                )
                ->first();
        }

        $urlObj = parse_url(env('WEB_FRONTEND_URL', ''));
        if (isset($_SERVER["HTTP_REFERER"])) {
            $urlObj = parse_url($_SERVER["HTTP_REFERER"]);
        }
        $host = (isset($domain->domain) && $domain->domain != '') ? $domain->domain :  $urlObj['host'];
        return $urlObj['scheme'] . '://' . $host . (($type == 1) ? '/PointAndRecord' : '/Deposit#result');
    }

    private function checkAutoDeposit($agent)
    {
        $result = false;
        $hasAutoDeposit = DB::table('agent_groups as ag')
            ->join('agent_cash_flows as acf', function ($join) {
                $join->on('acf.agent_group_id', 'ag.id')
                    ->where('acf.status', 1);
            })
            ->join('cash_flows as cf', function ($join) {
                $join->on('cf.id', 'acf.cash_flow_id')
                    ->where('cf.id', 5)
                    ->where('cf.status', 1);
            })
            ->where('ag.id', $agent->cash_flow_group_id)
            ->select('ag.*')
            ->first();
        $checkCustomized = $this->get_boolean(
            $agent->customized_setting_group_id,
            AgentCustomizedSettingKeyEnum::AUTODESPOSITE_BUTTON_VISIBLE
        );
        if (is_object($hasAutoDeposit) && $checkCustomized) {
            $result = true;
        }

        return $result;
    }

    private function checkWithdrawal($agent)
    {
        $result = false;
        $hasAutoDeposit = DB::table('agent_groups as ag')
            ->join('agent_cash_flows as acf', function ($join) {
                $join->on('acf.agent_group_id', 'ag.id')
                    ->where('acf.status', 1);
            })
            ->join('cash_flows as cf', function ($join) {
                $join->on('cf.id', 'acf.cash_flow_id')
                    ->where('cf.id', 6)
                    ->where('cf.status', 1);
            })
            ->where('ag.id', $agent->cash_flow_group_id)
            ->select('ag.*')
            ->first();
        $checkCustomized = $this->get_boolean(
            $agent->customized_setting_group_id,
            AgentCustomizedSettingKeyEnum::WITHDRAWAL_BUTTON_VISIBLE
        );
        if (is_object($hasAutoDeposit) && $checkCustomized) {
            $result = true;
        }

        return $result;
    }

    private function get_boolean($agent_group_id, $settingKey, $defaultValue = 0)
    {
        $find = DB::table('agent_customized_settings')
            ->where('agent_group_id', $agent_group_id)
            ->where('setting_key', $settingKey)->first();
        if (!is_object($find)) {
            return $defaultValue;
        }

        return $find->value == "1" ? 1 : 0;
    }

    private function get_target_language_settingText($agent_group_id, $lang, $settingKey)
    {
        $query = DB::table('agent_customized_settings')->where('agent_group_id', $agent_group_id);

        $find = $query->where('setting_key', $settingKey)
            ->where('sub_category', $lang)
            ->first();

        if (is_object($find)) {
            return $find->value;
        }

        //如果特定語系沒設 取第一個
        $findFirst= $query->where('setting_key', $settingKey)
            ->sortBy('sub_category')
            ->first();

        if (is_object($findFirst)) {
            return  $findFirst->value;
        }

        return "";
    }
}