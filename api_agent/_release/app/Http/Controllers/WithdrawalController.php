<?php

namespace App\Http\Controllers;

use App\Helpers\SQLHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Language;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Models\Agent;
use App\Models\BankName;
use App\Models\WithdrawalOrder;
use App\Models\Bank;
use App\Helpers\Common;
use App\Helpers\RandomHelper;
use App\REDIS;
use Validator;
use DB;
use log;

class WithdrawalController extends Controller
{
    /**
     * withdrawal/list
     * -訂單列表
     *
     * @bodyParam order_no integer 訂單系統編號 Example: 1
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam account_no string 銀行帳號 Example: 123456789
     * @bodyParam status integer 狀態 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "order_id": "訂單編號,ex:1",
     *            "bank_name": "銀行名稱,ex:馬來亞銀行",
     *            "bank_code": "銀行代碼,ex:mbb",
     *            "account_name": "帳戶名稱,ex:xxx",
     *            "account_no": "銀行帳號,ex:123456789",
     *            "username": "會員名稱,ex:aaa",
     *            "remark": "備註",
     *            "amount": "洗分金額,ex:100",
     *            "status": "狀態 0:訂單建立,1:待審核,2:失敗,3:通過,4:已轉帳,ex:4",
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
            $model = new WithdrawalOrder();

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hours'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hours'));

            //Admin要查到所有群組的訂單
            if($request->HOST != 1) {
                $agent = Agent::find($request->HOST);
                $agentGroupId = $agent->cash_flow_group_id;
            } else {
                $agentGroupId = 0;
            }

            //利用代付單號查出洗分單號
            if (isset($request->transaction_no) && $request->transaction_no != '') {
                $wpr = DB::table('withdrawal_payout_records')
                    ->where('transaction_no', $request->transaction_no)
                    ->first();
                $request->order_no = isset($wpr->withdrawal_order_id) ? $wpr->withdrawal_order_id : -1;
            }

            $sub = DB::table('withdrawal_payout_records');
            if (isset($request->order_no) && $request->order_no != '') {
                $sub = $sub->where('withdrawal_order_id', $request->order_no);
            }
            $sub = $sub->groupBy('withdrawal_order_id')
                ->orderBy('id', 'desc')
                ->select(
                    'withdrawal_order_id',
                    DB::raw('group_concat(transaction_no ORDER BY id DESC) as transaction_no'),
                    'third_party_transaction_no'
                );

            $model = $model->join('members as m', 'wo.member_id', 'm.id')
                ->leftJoin(DB::raw('(' . $sub->toSql() . ') as wpr' ), 'wpr.withdrawal_order_id', 'wo.id')
                ->mergeBindings($sub);

            if (isset($request->order_no) && $request->order_no != '') {
                $model = $model->where('wo.id', $request->order_no);
            } elseif (isset($request->member_username) && $request->member_username != '') {
                $model = $model->where('m.username', $request->member_username);
            } elseif (isset($request->account_no) && $request->account_no != '') {
                $model = $model->where('wo.account_name', $request->account_no);
            }

            if (isset($request->status) && $request->status != '' && $request->status != "-1") {
                $model = $model->where('wo.status', $request->status);
            }
            if($agentGroupId > 0){
                $model = $model->where('agent_group_id', $agentGroupId);
            }

            $model = $model->whereBetween('wo.created_at', [$start_time, $end_time]);
            $total = $model->count();
            $data = $model->orderBy('wo.created_at', 'desc')
                ->select(
                    DB::raw('DATE_ADD(wo.created_at,INTERVAL 8 HOUR) as create_at'),
                    'wo.id as order_id',
                    'wo.bank_name as bank_name',
                    'wo.bank_code as bank_code',
                    'wo.account_name as account_name',
                    'wo.account_no as account_no',
                    'm.username as username',
                    'wo.memo as remark',
                    DB::raw('TRUNCATE(wo.amount, 2) as amount'),
                    'wo.status as status',
                    'wpr.transaction_no',
                    'wpr.third_party_transaction_no'
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
     * withdrawal/getOrderInfo
     * -訂單詳細資料
     *
     * @bodyParam id integer required 訂單系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *          "id": "系統編號,ex:1",
     *          "bank_name": "銀行名稱,ex:馬來亞銀行",
     *          "bank_code": "銀行代碼,ex:mbb",
     *          "account_name": "帳戶名稱,ex:xxx",
     *          "account_no": "銀行帳號,ex:123456789",
     *          "username": "會員名稱,ex:aaa",
     *          "remark": "備註",
     *          "amount": "洗分金額,ex:100",
     *          "status": "狀態0建立1成功2失敗,ex:1",
     *          "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *    }
     * }
     */
    public function getOrderInfo(Request $request)
    {
        try {
            $model = new WithdrawalOrder();
            $data = $model->leftJoin('agents as operator_a', 'wo.operator', 'operator_a.id')
                ->join('members as m', 'wo.member_id', 'm.id')
                ->join('agents as a', 'a.id', 'm.parent')
                ->where('wo.id', $request->order_id)
                ->select(
                    'wo.id as id',
                    'wo.member_id as member_id',
                    'wo.bank_name as bank_name',
                    'wo.bank_code as bank_code',
                    'wo.account_name as account_name',
                    'wo.account_no as account_no',
                    'm.username as username',
                    'wo.memo as remark',
                    'wo.third_party_order_no as third_party_order_no',
                    DB::raw('TRUNCATE(wo.amount, 2) as amount'),
                    'wo.status as status',
                    'wo.created_at as created_at',
                    'wo.operator as operator',
                    'operator_a.username as operator_name',
                    'wo.check_time as check_time',
                    DB::raw('DATE_ADD(wo.created_at,INTERVAL 8 HOUR) as created_at'),
                    DB::raw('DATE_ADD(wo.check_time,INTERVAL 8 HOUR) as check_time'),
                    'a.username as agent_username',
                    'm.first_name as real_name'
                )
                ->first();

            //if (Common::IsNotNullOrEmptyString($data->third_party_order_no)) {
                //查出代付資訊
                $queryPayout = DB::table('withdrawal_payout_records as w')
                    ->leftJoin('cash_flows as cf', 'w.cash_flow_id', 'cf.id')
                    ->where('withdrawal_order_id', $data->id)
                    ->orderBy('is_success_payout', 'DESC')
                    ->orderBy('w.id', 'DESC')
                    ->select(
                        'w.*',
                        'cf.name as cf_name'
                    )
                    ->get();
                $data->detail = $queryPayout;
            //}

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

    /**
     * withdrawaleviewOrder
     * - 審核訂單
     *
     * @bodyParam id integer required 訂單系統編號 Example: 1
     * @bodyParam original_status integer required 原訂單狀態 Example: 4
     * @bodyParam status integer required 訂單狀態 Example: 4
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */
    public function reviewOrder(Request $request)
    {
        try {
            $model = new WithdrawalOrder();
            $check_time = date('Y-m-d H:i:s');
            $order = $model->where('id', $request->id)->first();

            if ($order->status == 2 || $order->status == 9) {
                //如果已經是失敗/退還 的狀態 禁止再進行操作了
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.200201')
                ]);

            } elseif ($order->status == $request->status) {
                //如果狀態一樣 (也許同時間有兩人同時操作?)
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.200202')
                ]);

            } elseif ($order->status != $request->original_status) {
                //如果訂單狀態不是原狀態, 代表此訂單已被異動過, 不能再繼續了
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.200203')
                ]);
            }

            $member = Member::find($order->member_id);
            if (($request->status == 2 || $request->status == 9) && $member->event_mode > 0) {
                //拒絕且玩家為鎖分模式中禁止退還
                return response()->json([
                    'code' => 2043,
                    'message' => __('error_message.2043')
                ]);
            }

            DB::beginTransaction();

            $member = Member::lockForUpdate()->find($order->member_id);

            $data = array();

            //如果有填寫備註，且備註為空 => 寫入
            //如果有填寫備註，且備註不為空 => 接一段文字在後面
            if (Common::IsNullOrEmptyString($order->memo)) {
                $data['memo'] = $request->remark;
            } else if (Common::IsNotNullOrEmptyString($request->remark)) {
                $data['memo'] = $order['memo'] . PHP_EOL . $request->remark . "";
            }

            //如果是 強制解除代付狀態 會清除代付單號
            if ($order->status == 22 && $request->status == 3) {
                $data['third_party_order_no'] = '';
            }

            if ($request->status != 4) {
                if (Common::IsNullOrEmptyString($order->check_time)) {
                    $data['check_time'] = $check_time;
                    $data['operator'] = $request->ID;  //這欄位就當作reviewer 好了
                }

                //需要退還金幣的情境
                if ($request->status == 2 || $request->status == 9) {
                    $before_balance = $member->balance;
                    $balance = ($member->balance + $order->amount);
                    $member->update([
                        'balance' => DB::raw('balance+' . $order->amount)
                    ]);

                    $log = array();
                    $log['transaction_no'] = $this->getTransactionNo();
                    $log['agent_id'] = $member->parent;
                    $log['member_id'] = $member->id;
                    $log['order_id'] = $order->id;
                    $log['point'] = $order->amount;
                    $log['before_balance'] = $before_balance;
                    $log['balance'] = $balance;
                    $log['type'] = 12;
                    $log['operator'] = $request->USERNAME;
                    $log['status'] = 1000;
                    $log['admin'] = $request->ID;
                    TransactionLog::create($log);
                }
            }

            if ($request->status == 4) {
                //將洗分紀錄 更新為成功 (會算進洗分報表)
                TransactionLog::where('order_id', $order->id)
                    ->where('type', 5)
                    ->update(
                        [
                            'operator' => $request->USERNAME,
                            'status' => 1000,
                            'admin' => $request->ID
                        ]
                    );
            } elseif ($order->status != 1 && $request->status == 3) {
                TransactionLog::where('order_id', $order->id)
                    ->where('type', 5)
                    ->update(
                        [
                            'operator' => $request->USERNAME,
                            'status' => 9,
                            'admin' => $request->ID
                        ]
                    );
            } elseif ($request->status == 2) {
                //如果拒絕把狀態改成-9
                TransactionLog::where('order_id', $order->id)
                    ->where('type', 5)
                    ->update(
                        [
                            'operator' => $request->USERNAME,
                            'status' => -9,
                            'admin' => $request->ID
                        ]
                    );
            } else {
                TransactionLog::where('order_id', $order->id)
                    ->where('type', 5)
                    ->update(
                        [
                            'operator' => $request->USERNAME,
                            'admin' => $request->ID
                        ]
                    );
            }

            $data['status'] = $request->status;
            $data["change_logs"] = $order["change_logs"] . PHP_EOL . "from " . $order->status . " to " . $request->status . " by " . $request->ID;

            $updateOrder = $order->update($data);

            if ($updateOrder) {
                DB::commit();
                //審核完畢,redis減少未處理的訂單數
                $this->reduceWithdrawRedis($order->agent_group_id);
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000')
                ]);
            } else {
                DB::rollback();
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002')
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollback();
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * withdrawal/showBankList
     * -取得銀行列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *          "bank_id": "系統編號,ex:1",
     *          "bank_name": "銀行名稱,ex:馬來亞銀行",
     *          "bank_code": "銀行代碼,ex:mbb",
     *          "status": "狀態0停用1開啟,ex:1",
     *    }
     * }
     */
    public function showBankList(Request $request)
    {
        try {
            $model = Bank::join('agent_groups as ag', 'ag.id', 'banks.agent_group_id')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.cash_flow_group_id', 'ag.id')
                        ->where('a.id', $request->HOST);
                });
            $total = $model->count();
            $data = $model->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    'banks.id as bank_id',
                    'banks.name as bank_name',
                    'banks.code as bank_code',
                    'banks.status as status'
                )
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
     * withdrawal/getBankInfo
     * -取得銀行詳細資料
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *          "bank_id": "系統編號,ex:1",
     *          "bank_name": "銀行名稱,ex:馬來亞銀行",
     *          "bank_code": "銀行代碼,ex:mbb",
     *          "status": "狀態0停用1開啟,ex:1",
     *    }
     * }
     */
    public function getBankInfo(Request $request)
    {
        try {
            $data = Bank::join('agent_groups as ag', 'ag.id', 'banks.agent_group_id')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.cash_flow_group_id', 'ag.id')
                        ->where('a.id', $request->HOST);
                })
                ->where('banks.id', $request->id)
                ->select(
                    'banks.id as id',
                    'banks.name as bank_name',
                    'banks.code as bank_code',
                    'banks.status as status'
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
     * withdrawal/addBank
     * -新增銀行
     *
     * @bodyParam id string required 營運商系統編號 Example: 1
     * @bodyParam bank_name integer required 銀行名稱 Example: XX銀行
     * @bodyParam bank_code integer required 銀行代碼 Example: aaa
     * @bodyParam status integer required 銀行狀態 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */
    public function addBank(Request $request)
    {
        try {
            $agent = Agent::find($request->HOST);
            $bank = Bank::where('code', $request->bank_code)
                ->where('agent_group_id', $agent->cash_flow_group_id)
                ->first();
            if (!is_object($bank)) {
                $data = array();
                $data['agent_group_id'] = $agent->cash_flow_group_id;
                $data['name'] = $request->bank_name;
                $data['code'] = $request->bank_code;
                $data['status'] = $request->status;
                if (Bank::create($data)) {
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
     * withdrawal/editBank
     * -編輯銀行
     *
     * @bodyParam id string required 營運商系統編號 Example: 1
     * @bodyParam bank_name string 帳戶名稱 Example: XX銀行
     * @bodyParam bank_code string 暱稱 Example: 456
     * @bodyParam status integer required 銀行狀態 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */
    public function editBank(Request $request)
    {
        try {
            $agent = Agent::find($request->HOST);
            $bank = Bank::where('code', $request->bank_code)
                ->where('agent_group_id', $agent->cash_flow_group_id)
                ->first();
            if (!is_object($bank)) {
                $data = array();
                $data['name'] = $request->bank_name;
                $data['code'] = $request->bank_code;
                $data['status'] = $request->status;
                if ($result = Bank::find($request->id)->update($data)) {
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
     * withdrawal/getBankName
     * -取得多語系銀行名稱
     *
     * @bodyParam id string required 營運商系統編號 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     *  "data": {
     *          "id": "語系編號,ex:1",
     *          "name": "語系名稱,ex:繁體中文",
     *          "bank_id": "銀行編號,ex:1",
     *          "bank_name": "銀行名稱,ex:XX銀行",
     *    }
     * }
     */
    public function getBankName(Request $request)
    {
        try {
            $data = Language::leftjoin('bank_names as bn', function ($join) use ($request) {
                $join->on('bn.language_id', 'languages.id')
                    ->where('bn.bank_id', $request->id);
            })
                ->leftjoin('banks as b', 'b.id', 'bn.bank_id')
                ->select(
                    'languages.id as id',
                    'languages.name as name',
                    'b.id as bank_id',
                    'bn.name as bank_name'
                )
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
     * withdrawal/editBankName
     * -編輯多語系銀行名稱
     *
     * @bodyParam id string required 營運商系統編號 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     *  "data": {
     *          "id": "語系編號,ex:1",
     *          "name": "語系名稱,ex:繁體中文",
     *          "bank_id": "銀行編號,ex:1",
     *          "bank_name": "銀行名稱,ex:XX銀行",
     *    }
     * }
     */
    public function editBankName(Request $request)
    {
        try {
            $bank = $request->bank;
            $data = array();
            foreach ($bank as $key => $value) {
                if (isset($value) && $value != '') {
                    $name = array();
                    $name['language_id'] = $key;
                    $name['bank_id'] = $request->id;
                    $name['name'] = $value;
                    array_push($data, $name);
                } else {
                    break;
                }
            }
            if (is_object(BankName::where('bank_id', $request->id)->get())) {
                BankName::where('bank_id', $request->id)->delete();
            }
            if (BankName::insert($data)) {
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

    public function modifyStatus(Request $request)
    {
        try {
            $bank = Bank::find($request->id);
            $data = array();
            $data['status'] = ($request->status != '') ? $request->status : $bank->status;
            if ($bank->update($data)) {
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

    private function getTransactionNo()
    {
        return RandomHelper::getTransactionNo();
    }

    private function reduceWithdrawRedis($agent_cash_flow_group_id)
    {
        $redis = new REDIS();
        $key = array(
            'notice',
            'withdrawal',
            $agent_cash_flow_group_id
        );
        //如果沒有資料,就查DB做寫入
        if (!$redis->exists($key)) {
            $model = WithdrawalOrder::where('agent_group_id', $agent_cash_flow_group_id)
                ->where('status', 1);
            $amount = $model->count();
            $last_order_id = $model->orderBy('id', 'DESC')->pluck('id')->first();
            $data = array(
                'amount' => $amount,
                'last_order_id' => $last_order_id
            );
            $redis->setCommon($key, $data);
        } else {
            $data = $redis->get($key);
            $data = array(
                'amount' => $data->amount--,
                'last_order_id' => $$data->last_order_id
            );
            $redis->setCommon($key, $data);
        }
    }
}