<?php


namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentCashFlowOrderType;
use App\Models\Language;
use App\Models\Order;
use App\Models\OrderTypeName;
use App\Models\OrderType;
use DB;
use App\Models\AgentCashFlow;
use Validator;
use Exception;
use Illuminate\Http\Request;
use App\Repositories\AgentRepository;
use App\Models\CashFlow;
use log;

class AgentOnlineDepositController extends Controller
{
    /**
     * agent/onlineDeposit/cashFlowList
     * - 取得支付渠道列表
     *
     * @bodyParam group_id integer required 支付群組編號
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function cashFlowList(Request $request)
    {
        try {
            // 驗證 group_id 合法性
            $agentRep = new AgentRepository();
            $agentGroup = $agentRep->getAgentGroup($request->HOST, $request->group_id);
            if(!$agentGroup || $agentGroup->agent_group_type != 2) {
                throw new Exception("group_id not exists", 9999);
            }

            //確認是否有開啟這個組別的權限
            $checkAgentGroupPermission = $this->checkAgentGroupPermission($request->group_id, $request->ID);
            //Admin跟Admin的子帳號不檢查
            if($request->HOST != 1 && !$checkAgentGroupPermission) {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003'),
                    'data' => ""
                ]);
            }

            // 獲取支付渠道列表
            $query = DB::table("agent_cash_flows as acf")
                ->join("cash_flows as cf" , "acf.cash_flow_id" , "cf.id")
                ->where("acf.agent_group_id", $request->group_id)
                ->select(
                    "acf.id",
                    "acf.rate",
                    "example.invalid",
                    "acf.sorts",
                    "acf.status",
                    "cf.id as cash_flow_id",
                    "cf.name",
                    "cf.type"
                );
            $agent_cash_flow_list = $query->get();
            $cash_flow_list = DB::table("cash_flows")->where("status", 1)->select("id", "name")->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'agent_group' => $agentGroup,
                    'agent_cash_flow_list' => $agent_cash_flow_list,
                    'cash_flow_list' => $cash_flow_list
                ],
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
     * agent/onlineDeposit/updateAgentCashFlow
     * - 取得支付渠道列表
     *
     * @bodyParam id integer optional acf_id
     * @bodyParam cash_flow_id integer optional acf_id
     * @bodyParam rate numeric required 點數轉換比例
     * @bodyParam sorts numeric required 排序
     * @bodyParam info string optional 商戶資料
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function updateAgentCashFlow(Request $request)
    {
        try {
            $rules = [
                'rate' => 'required|numeric',
                'sorts' => 'required|integer',
            ];
            if(!$request->id) { //新增資料要傳cash_flow_id, group_id
                $rules['cash_flow_id'] = 'required|integer';
                $rules['group_id'] = 'required|integer';
            }
            $validator = Validator::make($request->all(), $rules);
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            // 有傳 id 代表是更新資料
            if($request->id) {
                // 驗證 acf_id 合法性
                $agentCashFlow = AgentCashFlow::find($request->id);
                if (!$agentCashFlow) {
                    throw new Exception("acf_id not exists.", 9999);
                }

                $agentRep = new AgentRepository();
                $agentGroup = $agentRep->getAgentGroup($request->HOST, $agentCashFlow->agent_group_id);
                if(!$agentGroup || $agentGroup->agent_group_type != 2) {
                    throw new Exception("permission denied.", 9999);
                }
                $agentCashFlow->rate = $request->rate;
                $agentCashFlow->sorts = $request->sorts;
                $agentCashFlow->info = json_encode(json_decode($request->info, true));
                if (!$agentCashFlow->save()) {
                    throw new Exception("update failure.", 9999);
                };
            } else { //沒有 id 是新增資料
                $data = [
                    'cash_flow_id' => $request->cash_flow_id,
                    'agent_group_id' => $request->group_id,
                    'rate' => $request->rate,
                    'sorts' => $request->sorts,
                    'info' => json_encode(json_decode($request->info, true)),
                    'status' => 0,
                    'admin' => $request->ID,
                ];
                $agentCashFlow = AgentCashFlow::create($data);

                if (!$agentCashFlow) {
                    throw new Exception("create failure.", 9999);
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $agentCashFlow,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * api/agent/onlineDeposit/agentCashFlowToggleStatus
     * - 開關
     *
     * @bodyParam acf_id integer required 支付商戶編號
     * @bodyParam status integer required 狀態
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function agentCashFlowToggleStatus(Request $request)
    {
        try {
            // 驗證 acf_id 合法性
            $agentCashFlow = AgentCashFlow::find($request->acf_id);
            if (!$agentCashFlow) {
                throw new Exception("acf_id not exists.", 9999);
            }

            $agentRep = new AgentRepository();
            $agentGroup = $agentRep->getAgentGroup($request->HOST, $agentCashFlow->agent_group_id);
            if(!$agentGroup || $agentGroup->agent_group_type != 2) {
                throw new Exception("permission denied.", 9999);
            }

            $agentCashFlow->status = $request->status;
            if (!$agentCashFlow->save()) {
                throw new Exception("change status failure.", 9999);
            };
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $agentCashFlow,
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
     * agent/onlineDeposit/acfOrderTypeList
     * - 取得支付渠道列表
     *
     * @bodyParam id integer required 代理支付編號 （agent_cash_flow_id)
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function acfOrderTypeList(Request $request)
    {
        try {
            // 驗證 acf_id 合法性
            $agentCashFlow = AgentCashFlow::find($request->acf_id);
            if (!$agentCashFlow) {
                throw new Exception("acf_id not exists.", 9999);
            }

            $agentRep = new AgentRepository();
            $agentGroup = $agentRep->getAgentGroup($request->HOST, $agentCashFlow->agent_group_id);
            if(!$agentGroup || $agentGroup->agent_group_type != 2) {
                throw new Exception("permission denied.", 9999);
            }

            //確認是否有開啟這個組別的權限
            $checkAgentGroupPermission = $this->checkAgentGroupPermission($request->group_id, $request->ID);
            //Admin跟Admin的子帳號不檢查
            if($request->HOST != 1 && !$checkAgentGroupPermission) {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003'),
                    'data' => ""
                ]);
            }

            // 獲取支付渠道列表
            $query = DB::table("agent_cash_flow_order_types as acfot")
                ->join("agent_cash_flows as acf" , "acf.id" , "acfot.agent_cash_flow_id")
                ->join("order_types as ot", "ot.id", "acfot.order_type_id")
                ->where("acfot.agent_cash_flow_id", $request->acf_id)->where("ot.status", 1)
                ->select(
                    "acfot.id",
                    DB::raw('CONCAT(name, "(", `key`, ")") as name'),
                    "acfot.min_amount",
                    "acfot.max_amount",
                    "acfot.available_amount",
                    "acfot.sorts",
                    "acfot.status",
                    "acfot.order_type_id"
                );
            $acf_order_type_list = $query->get();
            $order_type_list = DB::table("order_types")
                ->where("status", 1)
                ->where('cash_flow_id', $agentCashFlow->cash_flow_id)
                ->select("id", DB::raw('CONCAT(name, "(", `key`, ")") as name'))
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'agentCashFlow' => $agentCashFlow,
                    'acf_order_type_list' => $acf_order_type_list,
                    'order_type_list' => $order_type_list
                ],
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
     * api/agent/onlineDeposit/agentCashFlowOrderTypeToggleStatus
     * - 開關
     *
     * @bodyParam acf_id integer required 支付商戶編號
     * @bodyParam status integer required 狀態
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function agentCashFlowOrderTypeToggleStatus(Request $request)
    {
        try {
            // 驗證 acf_id 合法性
            $agentCashFlowOrderType = AgentCashFlowOrderType::find($request->id);
            if (!$agentCashFlowOrderType) {
                throw new Exception("id not exists.", 9999);
            }
            $agentCashFlow = AgentCashFlow::find($agentCashFlowOrderType->agent_cash_flow_id);
            if (!$agentCashFlowOrderType) {
                throw new Exception("agent cash flow not exists.", 9999);
            }

            $agentRep = new AgentRepository();
            $agentGroup = $agentRep->getAgentGroup($request->HOST, $agentCashFlow->agent_group_id);
            if(!$agentGroup || $agentGroup->agent_group_type != 2) {
                throw new Exception("permission denied.", 9999);
            }

            $agentCashFlowOrderType->status = $request->status;
            if (!$agentCashFlowOrderType->save()) {
                throw new Exception("change status failure.", 9999);
            };
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $agentCashFlowOrderType,
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
     * agent/onlineDeposit/updateAgentCashFlowOrderType
     * - 更新支付方式資料
     *
     * @bodyParam id integer optional acfot_id
     * @bodyParam order_type_id integer optional order_type_id
     * @bodyParam min_amount numeric required 最小限額
     * @bodyParam max_amount numeric required 最大限額
     * @bodyParam sorts integer required 排序
     * @bodyParam available_amount string optional 固額清單
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function updateAgentCashFlowOrderType(Request $request)
    {
        try {
            $rules = [
                'min_amount' => 'required|numeric',
                'max_amount' => 'required|numeric',
                'sorts' => 'required|integer',
            ];
            if(!$request->id) { //新增資料要傳order_type_id
                $rules['order_type_id'] = 'required|integer';
                $rules['agent_cash_flow_id'] = 'required|integer';
            }
            $validator = Validator::make($request->all(), $rules);
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            // 有傳 id 代表是更新資料
            if($request->id) {
                // 驗證 id 合法性
                $agentCashFlowOrderType = AgentCashFlowOrderType::find($request->id);
                if (!$agentCashFlowOrderType) {
                    throw new Exception("id not exists.", 9999);
                }
                $agentCashFlow = AgentCashFlow::find($agentCashFlowOrderType->agent_cash_flow_id);
                if (!$agentCashFlowOrderType) {
                    throw new Exception("id not exists.", 9999);
                }
                $agentRep = new AgentRepository();
                $agentGroup = $agentRep->getAgentGroup($request->HOST, $agentCashFlow->agent_group_id);
                if(!$agentGroup || $agentGroup->agent_group_type != 2) {
                    throw new Exception("permission denied.", 9999);
                }
                $agentCashFlowOrderType->min_amount = $request->min_amount;
                $agentCashFlowOrderType->max_amount = $request->max_amount;
                $agentCashFlowOrderType->sorts = $request->sorts;
                $agentCashFlowOrderType->available_amount = $request->available_amount;
                if (!$agentCashFlowOrderType->save()) {
                    throw new Exception("update failure.", 9999);
                };
            } else { //沒有 id 是新增資料
                $orderType = OrderType::find($request->order_type_id);
                if (!$orderType) {
                    throw new Exception("order type not exists.", 9999);
                }
                $agentCashFlow = AgentCashFlow::find($request->agent_cash_flow_id);
                if (!$agentCashFlow) {
                    throw new Exception("agent cash flow not exists.", 9999);
                }
                $agentRep = new AgentRepository();
                $agentGroup = $agentRep->getAgentGroup($request->HOST, $agentCashFlow->agent_group_id);
                if (!$agentGroup || $agentGroup->agent_group_type != 2) {
                    throw new Exception("permission denied.", 9999);
                }

                $data = [
                    'agent_cash_flow_id' => $request->agent_cash_flow_id,
                    'order_type_id' => $request->order_type_id,
                    'min_amount' => $request->min_amount,
                    'max_amount' => $request->max_amount,
                    'sorts' => $request->sorts,
                    'available_amount' => $request->available_amount,
                    'status' => 0,
                ];
                $agentCashFlowOrderType = AgentCashFlowOrderType::create($data);

                if (!$agentCashFlowOrderType) {
                    throw new Exception("create failure.", 9999);
                }
            }
            $acf_order_type_list = $this->getAgentCashFlowOrderTypeList($request->agent_cash_flow_id);
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'agentCashFlow' => $agentCashFlow,
                    'acf_order_type_list' => $acf_order_type_list,
                ],
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * api/agent/onlineDeposit/agentCashFlowOrderTypeDelete
     * - 刪除支付方式
     *
     * @bodyParam id integer required 支付方式編號
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function agentCashFlowOrderTypeDelete(Request $request)
    {
        try {
            // 驗證 id 合法性
            $agentCashFlowOrderType = AgentCashFlowOrderType::find($request->id);
            if (!$agentCashFlowOrderType) {
                throw new Exception("id not exists.", 9999);
            }
            $agentCashFlow = AgentCashFlow::find($agentCashFlowOrderType->agent_cash_flow_id);
            if (!$agentCashFlowOrderType) {
                throw new Exception("agent cash flow not exists.", 9999);
            }

            $agentRep = new AgentRepository();
            $agentGroup = $agentRep->getAgentGroup($request->HOST, $agentCashFlow->agent_group_id);
            if(!$agentGroup || $agentGroup->agent_group_type != 2) {
                throw new Exception("permission denied.", 9999);
            }

            if (!$agentCashFlowOrderType->delete()) {
                throw new Exception("delete failure.", 9999);
            };
            $acf_order_type_list = $this->getAgentCashFlowOrderTypeList($agentCashFlow->id);
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'agentCashFlow' => $agentCashFlow,
                    'acf_order_type_list' => $acf_order_type_list,
                ],
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

    private function getAgentCashFlowOrderTypeList($agent_cash_flow_id)
    {
        $query = DB::table("agent_cash_flow_order_types as acfot")
            ->join("agent_cash_flows as acf" , "acf.id" , "acfot.agent_cash_flow_id")
            ->join("order_types as ot", "ot.id", "acfot.order_type_id")
            ->where("acfot.agent_cash_flow_id", $agent_cash_flow_id)->where("ot.status", 1)
            ->select(
                "acfot.id",
                DB::raw('CONCAT(name, "(", `key`, ")") as name'),
                "acfot.min_amount",
                "acfot.max_amount",
                "acfot.available_amount",
                "acfot.sorts",
                "acfot.status",
                "acfot.order_type_id"
            );
        $acf_order_type_list = $query->get();
        return $acf_order_type_list;
    }

    public function getCashFlow(Request $request)
    {
        try {
            $cashFlow = DB::table('cash_flows')->select('id', 'name', 'type', 'status')->get();
            $language = DB::table('languages')->select('id', 'name')->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'cash_flow_list' => $cashFlow,
                    'language_list' => $language
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

    /**
     * api/agent/onlineDeposit/cashFlowToggleStatus
     * - 開關
     *
     * @bodyParam acf_id integer required 支付商戶編號
     * @bodyParam status integer required 狀態
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function cashFlowToggleStatus(Request $request)
    {
        try {
            // 驗證 acf_id 合法性
            $cashFlow = CashFlow::find($request->cf_id);
            if (!$cashFlow) {
                throw new Exception("ccf_id not exists.", 9999);
            }

            $cashFlow->status = $request->status;
            if (!$cashFlow->save()) {
                throw new Exception("change status failure.", 9999);
            };
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $cashFlow,
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
     * agent/onlineDeposit/updateAgentCashFlow
     * - 取得支付渠道列表
     *
     * @bodyParam id integer optional acf_id
     * @bodyParam cash_flow_id integer optional acf_id
     * @bodyParam rate numeric required 點數轉換比例
     * @bodyParam sorts numeric required 排序
     * @bodyParam info string optional 商戶資料
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function updateCashFlow(Request $request)
    {
        try {
            $rules = [
                'name' => 'required',
                'type' => 'required|in:1,2',
            ];
            $validator = Validator::make($request->all(), $rules);
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            // 有傳 id 代表是 [更新] 資料
            if ($request->id) {
                // 驗證 acf_id 合法性
                $cashFlow = CashFlow::find($request->id);
                if (!$cashFlow) {
                    throw new Exception("cf_id not exists.", 9999);
                }
                $cashFlow->name = $request->name;
                $cashFlow->type = $request->type;
                if (!$cashFlow->save()) {
                    throw new Exception("update failure.", 9999);
                };
            } else { //沒有 id 是 [新增] 資料
                $data = [
                    'name' => $request->name,
                    'type' => $request->type,
                    'status' => 1,
                    'admin' => $request->ID,
                ];
                $cashFlow = CashFlow::create($data);

                if (!$cashFlow) {
                    throw new Exception("create failure.", 9999);
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $cashFlow
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * agent/onlineDeposit/getOrderTypeList
     * - 取得支付渠道列表
     *
     * @bodyParam id integer required 代理支付編號 （agent_cash_flow_id)
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function getOrderTypeList(Request $request)
    {
        try {
            // 驗證 acf_id 合法性
            $cashFlow = CashFlow::find($request->cf_id);
            if (!$cashFlow) {
                throw new Exception("cf_id not exists.", 9999);
            }
            $data = OrderType::where('cash_flow_id', $request->cf_id)
                ->join('cash_flows as cf', 'cf.id', 'order_types.cash_flow_id')
                ->select(
                    'order_types.id as id',
                    'order_types.name as name',
                    'cf.id as cash_flow_id',
                    'cf.name as cash_flow_name',
                    'order_types.key as key',
                    'order_types.sorts as sorts',
                    'order_types.status as status'
                )
                ->get();

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

    private function getCashFlowOrderTypeList($cash_flow_id)
    {

        $query = OrderType::where('cash_flow_id', $cash_flow_id)
            ->join('cash_flows as cf', 'cf.id', 'order_types.cash_flow_id')
            ->select(
                'order_types.id as id',
                'order_types.name as name',
                'cf.id as cash_flow_id',
                'cf.name as cash_flow_name',
                'order_types.key as key',
                'order_types.sorts as sorts',
                'order_types.status as status'
            );
        $cash_flow_order_type_list = $query->get();
        return $cash_flow_order_type_list;
    }

    /**
     * api/agent/onlineDeposit/cashFlowOrderTypeToggleStatus
     * - 開關
     *
     * @bodyParam acf_id integer required 支付商戶編號
     * @bodyParam status integer required 狀態
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function cashFlowOrderTypeToggleStatus(Request $request)
    {
        try {
            // 驗證 acf_id 合法性
            $orderType = OrderType::find($request->id);
            if (!$orderType) {
                throw new Exception("id not exists.", 9999);
            }
            $cashFlow = CashFlow::find($orderType->cash_flow_id);
            if (!$cashFlow) {
                throw new Exception("agent cash flow not exists.", 9999);
            }

            $orderType->status = $request->status;
            if (!$orderType->save()) {
                throw new Exception("change status failure.", 9999);
            };
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $orderType,
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
     * agent/onlineDeposit/updateCashFlowOrderType
     * - 取得支付渠道列表
     *
     * @bodyParam id integer optional acf_id
     * @bodyParam cash_flow_id integer optional acf_id
     * @bodyParam rate numeric required 點數轉換比例
     * @bodyParam sorts numeric required 排序
     * @bodyParam info string optional 商戶資料
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": { }
     * }
     */
    public function updateCashFlowOrderType(Request $request)
    {
        try {
            $rules = [
                'name' => 'required',
                'key' => 'required'
            ];
            $validator = Validator::make($request->all(), $rules);
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }


            // 有傳 id 代表是更新資料
            if ($request->id) {
                // 驗證 cf_id 合法性
                $orderType = OrderType::find($request->id);
                if (!$orderType) {
                    throw new Exception("ot_id not exists.", 9999);
                }
                $cashFlow = CashFlow::find($orderType->cash_flow_id);
                if (!$cashFlow) {
                    throw new Exception("cf_id not exists.", 9999);
                }
                $orderType->name = $request->name;
                $orderType->key = $request->key;
                $orderType->sorts = $request->sorts;
                if (!$orderType->save()) {
                    throw new Exception("update failure.", 9999);
                };
            } else {
                //沒有 id 是新增資料
                $cashFlow = CashFlow::find($request->cash_flow_id);
                if (!$cashFlow) {
                    throw new Exception("cf_id not exists.", 9999);
                }
                $data = [
                    'cash_flow_id' => $request->cash_flow_id,
                    'name' => $request->name,
                    'key' => $request->key,
                    'sorts' => $request->sorts,
                    'status' => 0,
                    'admin' => $request->ID,
                ];
                $orderType = OrderType::create($data);

                if (!$orderType) {
                    throw new Exception("create failure.", 9999);
                }
            }
            $order_type_list = $this->getCashFlowOrderTypeList($request->cash_flow_id);
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'cashFlow' => $cashFlow,
                    'cash_flow_order_type_list' => $order_type_list,
                ],
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getCashFlowOrderTypeName(Request $request)
    {
        try {
            $orderType = OrderType::find($request->id);
            if (!$orderType) {
                throw new Exception("ot_id not exists.", 9999);
            }
            $orderTypeName = Language::leftjoin('order_type_names as otn', function ($join) use ($request) {
                $join->on('otn.language_id', 'languages.id')
                    ->where('otn.type_id', $request->id);
            })
                ->select(
                    'otn.id as id',
                    'languages.id as language_id',
                    'otn.name as name'
                )
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'order_type' => $orderType,
                    'order_type_name_list' => $orderTypeName
                ]
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    public function updateCashFlowOrderTypeName(Request $request)
    {
        try {
            $orderType = OrderType::find($request->id);
            if (!$orderType) {
                throw new Exception("ot_id not exists.", 9999);
            }
            if (is_object(OrderTypeName::where('type_id', $request->id)->get())) {
                OrderTypeName::where('type_id', $request->id)->delete();
            }
            $data = array();
            foreach ($request->name as $key => $value) {
                if (isset($value) && $value != '') {
                    $name = array();
                    $name['language_id'] = $key;
                    $name['type_id'] = $request->id;
                    $name['name'] = $value;
                    array_push($data, $name);
                }
            }
            if (OrderTypeName::insert($data)) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                ]);
            } else {
                return response()->json([
                    'code' => 2001,
                    'message' => __('error_message.2001'),
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function checkAgentGroupPermission($agent_group_id, $agent_id)
    {
        $result = false;
        $agent_group_permissions = DB::table('agent_group_permissions')
            ->where('agent_id', $agent_id)
            ->where('agent_group_id', $agent_group_id)
            ->first();
        if(is_object($agent_group_permissions)) {
            $result = true;
        }

        return $result;
    }
}