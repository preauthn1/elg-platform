<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Agent;
use App\Models\OrderType;
use App\Models\AgentCashFlowOrderType;
use App\Models\BankAccount;
use App\Helpers\Common;
use Hash;
use Validator;
use DB;
use App\Services\CSDataSyncService;
use log;

class BankController extends Controller
{
    /**
     * bank/list
     * -銀行帳號列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "bank_name": "銀行名稱,ex:馬來亞銀行",
     *            "bank_code": "銀行代碼,ex:000",
     *            "account_no": "銀行帳號,ex:0",
     *            "status": "狀態,ex:0",
     *        }
     *    ],
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     */

    public function list(Request $request)
    {
        try {
            $model = DB::table('bank_accounts as ba')
                ->join('order_types as ot', 'ot.id', 'ba.order_type_id')
                ->join('agent_cash_flow_order_types as acfot', 'acfot.order_type_id', 'ot.id')
                ->join('agent_cash_flows as acf', function ($join) {
                    $join->on('acf.id', 'acfot.agent_cash_flow_id')
                        ->where('acf.cash_flow_id', 5);
                })
                ->join('agent_groups as ag', 'ag.id', 'acf.agent_group_id')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.cash_flow_group_id', 'ag.id')
                        ->where('a.id', $request->HOST);
                });

            $total = $model->count();
            $data = $model->select(
                'ot.id as bank_id',
                'ba.id as account_id',
                'ot.name as bank_name',
                'ot.key as bank_code',
                'ba.account_name as account_name',
                'ba.account_no as account_no',
                DB::raw('TRUNCATE(ba.min_amount, 0) as min_amount'),
                DB::raw('TRUNCATE(ba.max_amount, 0) as max_amount'),
                'ba.status as status'
            )
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
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
     * bank/showBankList
     * -銀行列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "bank_name": "銀行名稱,ex:馬來亞銀行",
     *            "bank_code": "銀行代碼,ex:000",
     *            "status": "狀態,ex:0",
     *        }
     *    ],
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     */

    public function showBankList(Request $request)
    {
        try {
            $model = DB::table('order_types as ot')
                ->join('agent_cash_flow_order_types as acfot', 'acfot.order_type_id', 'ot.id')
                ->join('agent_cash_flows as acf', function ($join) {
                    $join->on('acf.id', 'acfot.agent_cash_flow_id')
                        ->where('acf.cash_flow_id', 5);
                })
                ->join('agent_groups as ag', 'ag.id', 'acf.agent_group_id')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.cash_flow_group_id', 'ag.id')
                        ->where('a.id', $request->HOST);
                });

            $total = $model->count();
            $data = $model->select(
                'ot.id as bank_id',
                'ot.name as bank_name',
                'ot.key as bank_code',
                'ot.status'
            )
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
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
     * bank/view
     * -銀行詳細資料
     *
     * @bodyParam id string required 銀行帳號編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *        "id": "銀行系統編號,ex:1",
     *        "bank_name": "銀行名稱,ex:1",
     *        "bank_code": "銀行代碼,ex:0",
     *        "status": "狀態,ex:1",
     *    }
     * }
     */

    public function show(Request $request)
    {
        try {
            $model = new OrderType();

            $data = $model->where('cash_flow_id', 5)
                ->where('id', $request->id)
                ->select(
                    'id',
                    'name as bank_name',
                    'key as bank_code',
                    'status'
                )
                ->first();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
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
     * bank/showAccount
     * -銀行帳戶詳細資料
     *
     * @bodyParam id string required 銀行帳號編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *        "id": "銀行帳戶編號,ex:1",
     *        "account_name": "帳戶名稱,ex:abc123",
     *        "account_no": "銀行帳戶,ex:123456789",
     *        "status": "狀態,ex:1",
     *    }
     * }
     */

    public function showAccount(Request $request)
    {
        try {
            $model = new BankAccount();

            $data = $model->Join('order_types as ot', 'ot.id', 'bank_accounts.order_type_id')
                ->where('bank_accounts.id', $request->id)
                ->select(
                    'ot.name as bank_name',
                    'bank_accounts.account_name as account_name',
                    'bank_accounts.account_no as account_no',
                    DB::raw('TRUNCATE(min_amount, 0) as min_amount'),
                    DB::raw('TRUNCATE(max_amount, 0) as max_amount'),
                    'bank_accounts.status as status'
                )
                ->first();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
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
     * bank/add
     * -新增營運商(只能新增自己的下線)
     *
     * @bodyParam bank_name string 銀行名稱 Example: XX銀行
     * @bodyParam bank_code 銀行代碼 Example: 123
     * @bodyParam status 狀態 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "系統編號,ex:1"
     * }
     */

    public function create(Request $request)
    {
        try {
            $bank = $model = DB::table('order_types as ot')
                ->join('agent_cash_flow_order_types as acfot', 'acfot.order_type_id', 'ot.id')
                ->join('agent_cash_flows as acf', function ($join) {
                    $join->on('acf.id', 'acfot.agent_cash_flow_id')
                        ->where('acf.cash_flow_id', 5);
                })
                ->join('agent_groups as ag', 'ag.id', 'acf.agent_group_id')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.cash_flow_group_id', 'ag.id')
                        ->where('a.id', $request->HOST);
                })
                ->where('ot.key', $request->bank_code)
                ->first();

            if (!is_object($bank)) {
                $data = array();
                $data['cash_flow_id'] = 5;
                $data['name'] = $request->bank_name;
                $data['key'] = $request->bank_code;
                $data['status'] = $request->status;
                if ($result = OrderType::create($data)) {
                    $CashFlow = DB::table('agent_cash_flows as acf')
                        ->join('agent_groups as ag', 'ag.id', 'acf.agent_group_id')
                        ->join('agents as a', function ($join) use ($request) {
                            $join->on('a.cash_flow_group_id', 'ag.id')
                                ->where('a.id', $request->HOST);
                        })
                        ->where('acf.cash_flow_id', 5)
                        ->select('acf.id as id')
                        ->first();

                    $CashFlowData = array();
                    $CashFlowData['agent_cash_flow_id'] = $CashFlow->id;
                    $CashFlowData['order_type_id'] = $result->id;
                    $CashFlowData['min_amount'] = 0;
                    $CashFlowData['max_amount'] = 0;
                    $CashFlowData['available_amount'] = '';
                    $CashFlowData['sorts'] = 0;
                    $CashFlowData['status'] = 1;
                    if (AgentCashFlowOrderType::create($CashFlowData)) {
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => $result->id
                        ]);
                    }
                } else {
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001'),
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 2999,
                    'message' => __('error_message.2999'),
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

    /**
     * agent/edit
     * -編輯銀行
     *
     * @bodyParam id string required 營運商系統編號 Example: 1
     * @bodyParam bank_name string 帳戶名稱 Example: XX銀行
     * @bodyParam bank_code string 暱稱 Example: 456
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */
    public function edit(Request $request)
    {
        try {
            $bank = $model = DB::table('order_types as ot')
                ->join('agent_cash_flow_order_types as acfot', 'acfot.order_type_id', 'ot.id')
                ->join('agent_cash_flows as acf', function ($join) {
                    $join->on('acf.id', 'acfot.agent_cash_flow_id')
                        ->where('acf.cash_flow_id', 5);
                })
                ->join('agent_groups as ag', 'ag.id', 'acf.agent_group_id')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.cash_flow_group_id', 'ag.id')
                        ->where('a.id', $request->HOST);
                })
                ->where('ot.key', $request->bank_code)
                ->first();

            if (!is_object($bank)) {
                $data = array();
                $data['name'] = $request->bank_name;
                $data['key'] = $request->bank_code;
                $data['status'] = $request->status;
                if ($result = OrderType::where('id', $request->id)->where('cash_flow_id', 5)->update($data)) {
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
            } else {
                return response()->json([
                    'code' => 2999,
                    'message' => __('error_message.2999'),
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

    /**
     * agent/editAccount
     * -編輯銀行帳戶
     *
     * @bodyParam id string required 營運商系統編號 Example: 1
     * @bodyParam account_name string 帳戶名稱 Example: abc123
     * @bodyParam account_no string 暱稱 Example: 123456789
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */

    public function editAccount(Request $request)
    {
        try {
            $data = array();
            $data['account_name'] = $request->account_name;
            $data['account_no'] = $request->account_no;
            $data['min_amount'] = $request->min_amount;
            $data['max_amount'] = $request->max_amount;
            $data['status'] = $request->status;
            if ($result = BankAccount::where('id', $request->id)->update($data)) {
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
            $info = $e->errorInfo;
            return response()->json(['code' => $info[0],
                'message' => $info[2]]);
        } catch
        (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * bank/addAccount
     * -新增營運商(只能新增自己的下線)
     *
     * @bodyParam order_type_id string required 銀行ID Example: 11
     * @bodyParam account_name string required 帳戶名稱 Example: abc123
     * @bodyParam account_no string required 銀行帳號 Example: 123456789
     * @bodyParam status string required 狀態 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "系統編號,ex:1"
     * }
     */

    public function createAccount(Request $request)
    {
        try {
            $bank = OrderType::where('key', $request->bank_code)->first();
            if (!is_object($bank)) {
                $data = array();
                $data['order_type_id'] = $request->order_type_id;
                $data['account_name'] = $request->account_name;
                $data['account_no'] = $request->account_no;
                $data['min_amount'] = $request->min_amount;
                $data['max_amount'] = $request->max_amount;
                $data['status'] = $request->status;
                if ($result = BankAccount::create($data)) {
                    if (BankAccount::where('id', '>', 0)->update(['use_times' => 0])) {
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                        ]);
                    }
                } else {
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001'),
                    ]);
                }
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

    /**
     * bank/dropdownList
     * -銀行列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "營運商系統編號,ex:1",
     *            "bank_name": "銀行名稱,ex:XX銀行",
     *        }
     *    ]
     * }
     */
    public function dropdownList(Request $request)
    {
        try {
            $model = new OrderType();
            $data = $model
                ->join('agent_cash_flow_order_types as acfot', 'acfot.order_type_id', 'order_types.id')
                ->join('agent_cash_flows as acf', function ($join) {
                    $join->on('acf.id', 'acfot.agent_cash_flow_id')
                        ->where('acf.cash_flow_id', 5);
                })
                ->join('agent_groups as ag', 'ag.id', 'acf.agent_group_id')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.cash_flow_group_id', 'ag.id')
                        ->where('a.id', $request->HOST);
                })
                ->where('order_types.status', 1)
                ->select('order_types.id', 'order_types.name as bank_name')
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
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
     * bank/status
     * -更改銀行帳戶狀態
     *
     * @bodyParam id string required 銀行編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     * }
     */
    public function modifyStatus(Request $request)
    {
        if ($request->type == 1) {
            $model = new OrderType();
        } else {
            $model = new BankAccount();
        }
        if ($row = $model->find($request->id)) {
            $data = array();
            $data['status'] = ($request->status != '') ? $request->status : $row->status;
            if ($row->update($data)) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000')
                ]);
            } else {
                return response()->json([
                    'code' => 2001,
                    'message' => __('error_message.2001')
                ]);
            }
        } else {
            // 查無記錄
            return response()->json([
                'code' => 1901,
                'message' => __('error_message.1901')
            ]);
        }
    }

    private function messages()
    {
        return [

            'bank_code has exist' => __('error_message.2001')
        ];
    }
}