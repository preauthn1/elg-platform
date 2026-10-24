<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MemberBankAccount;
use App\Models\Member;
use App\Models\Agent;
use App\Models\WithdrawalOrder;
use App\Models\TransactionLog;
use App\Models\Bank;
use DB;
use log;

class WithdrawalController extends Controller
{
    /**
     * withdrawal/check
     * - 檢查是否有綁定銀行帳號
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "bank_name": "銀行名稱,ex:XX銀行",
     *            "account_name": "帳戶名稱,ex:abc111",
     *            "account_no": "銀行帳戶,ex:123456789"
     *        }
     *    ]
     * }
     */
    public function check(Request $request)
    {
        try {
            $member = Member::find($request->ID);
            $account = $this->getMemberAccount($request->ID);
            $data = array();
            if ($member->mobile_validate == 1) {
                $data['mobile'] = $member->mobile;
            } else {
                $data['mobile'] = null;
            }
            if (is_object($account)) {
                $data['bank_name'] = $account->bank_name;
                $data['bank_code'] = $account->bank_code;
                $data['account_name'] = $account->account_name;
                $data['account_no'] = $account->account_no;
            } else {
                $data['bank_name'] = null;
                $data['bank_code'] = null;
                $data['account_name'] = null;
                $data['account_no'] = null;
            }
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
     * withdrawal/getBankList
     * - 取得銀行清單
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "bank_name": "銀行名稱,ex:XX銀行",
     *            "account_name": "帳戶名稱,ex:abc111",
     *            "account_no": "銀行帳戶,ex:123456789"
     *        }
     *    ]
     * }
     */
    public function getBankList(Request $request)
    {
        try {
            $bank = Bank::join('agent_groups as ag', 'ag.id', 'banks.agent_group_id')
                ->join('agents as a', 'a.cash_flow_group_id', 'ag.id')
                ->join('members as m', function ($join) use ($request) {
                    $join->on('m.parent', 'a.id')
                        ->where('m.id', $request->ID);
                })
                ->leftjoin('bank_names as bn', function ($join) use ($request) {
                    $join->on('bn.bank_id', 'banks.id')
                        ->where('bn.language_id', $request->LANGUAGE);
                })
                ->where('banks.status', 1)
                ->select(
                    'banks.id as bank_id',
                    DB::raw("(CASE WHEN bn.name is NULL THEN banks.name ELSE bn.name END) as bank_name"),
                    'banks.code as bank_code'
                )
                ->get();
            if (is_object($bank)) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $bank
                ]);
            } else {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
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
     * withdrawal/account
     * - 綁定銀行帳號
     *
     * @bodyParam bank_id integer required 銀行系統編號
     * @bodyParam account_name string required 帳戶名稱
     * @bodyParam account_no integer required 銀行帳戶
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     *    ]
     * }
     */
    public function account(Request $request)
    {
        try {
            $account = MemberBankAccount::where('member_id', $request->ID)->first();
            $data = array();
            $data['bank_id'] = $request->bank_id;
            $data['account_name'] = $request->account_name;
            $data['account_no'] = $request->account_no;

            if (is_object($account)) {
                if ($account->update($data)) {
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                } else {
                    return response()->json([
                        'code' => 2002,
                        'message' => __('error_message.2002'),
                    ]);
                }
            } else {
                $data['member_id'] = $request->ID;
                $data['operator_cs'] = 0;

                if (MemberBankAccount::create($data)) {
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                } else {
                    return response()->json([
                        'code' => 2002,
                        'message' => __('error_message.2002'),
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
     * withdrawal
     * - 新建自助洗分訂單
     *
     * @bodyParam amount integer 金額 Example: 100
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     *    ]
     * }
     */
    public function withdrawal(Request $request)
    {
        try {
            //增加稽核判斷
            if (app('App\Http\Controllers\FlowLimitController')->checkFlowLimit($request->ID)) {
                return response()->json([
                    'code' => 1980,
                    'message' => __('error_message.1980'),
                ]);
            }
            
            $member = Member::find($request->ID);
            //先檢查餘額有沒有足夠
            if ($request->amount > $member->balance) {
                return response()->json([
                    'code' => 1022,
                    'message' => __('error_message.1022'),
                ]);
            }
            DB::beginTransaction();
            $before_balance = $member->balance;
            $balance = ($member->balance - $request->amount);
            $member->update([
                'balance' => DB::raw('balance-' . $request->amount)
            ]);
            $account = $this->getMemberAccount($request->ID);
            $agent = Agent::find($member->parent);

            $data = array();
            $data['bank_name'] = $account->bank_name;
            $data['bank_code'] = $account->bank_code;
            $data['account_name'] = $account->account_name;
            $data['account_no'] = $account->account_no;
            $data['agent_group_id'] = $agent->cash_flow_group_id;
            $data['member_id'] = $request->ID;
            $data['amount'] = $request->amount;
            $data['third_party_order_no'] = ' ';
            $data['status'] = 1;

            $order = WithdrawalOrder::create($data);

            $log = array();
            $log['transaction_no'] = $this->getTransactionNo();
            $log['agent_id'] = $member->parent;
            $log['member_id'] = $request->ID;
            $log['order_id'] = $order->id;
            $log['point'] = $order->amount;
            $log['before_balance'] = $before_balance;
            $log['balance'] = $balance;
            $log['type'] = 5;
            $log['operator'] = $member->username;
            $log['status'] = 9; //未審核前 狀態先保持在9
            
            $transaction_log = TransactionLog::create($log);

            DB::commit();
            if ($transaction_log) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                ]);
            } else {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = json_encode($e->errorInfo);
            Log::error($info);
            DB::rollback();
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            DB::rollback();
        }
    }

    /**
     * withdrawal/list
     * - 自助洗分清單
     *
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *            "amount": "金額,ex:100",
     *            "status": "狀態0建立1成功2失敗,ex:1"
     *        },
     *        {
     *            "id": 2,
     *            "created_at": "2019-03-06 16:55:22",
     *            "amount": 300,
     *            "status": 2,
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
    public function withdrawalList(Request $request)
    {
        try {
            $request->page = (isset($request->page)) ? $request->page : 1;
            $request->per_page = (isset($request->per_page)) ? $request->per_page : 10;

            $model = WithdrawalOrder::where('member_id', $request->ID);
            $total = $model->count();
            $data = $model->orderBy('created_at', 'DESC')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    'id',
                    DB::raw('date_add(created_at, interval 8 hour) as created_at'),
                    'amount',
                    DB::raw("CASE status WHEN 1 THEN '" . __('param.pending_review') . "' " .
                        "WHEN 21 THEN '" . __('param.pending_review') . "' " .
                        "WHEN 22 THEN '" . __('param.pending_review') . "' " .
                        "WHEN 24 THEN '" . __('param.pending_review') . "' " .
                        "WHEN 2 THEN '" . __('param.failure') . "' " .
                        "WHEN 29 THEN '" . __('param.failure') . "' " .
                        "WHEN 3 THEN '" . __('param.pass') . "' " .
                        "WHEN 4 THEN '" . __('param.transferred') . "' " .
                        "WHEN 9 THEN '" . __('param.refund') . "' " .
                        "ELSE '" . __('param.order_creation') . "' END AS status")
                )
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = json_encode($e->errorInfo);
            Log::error($info);
            DB::rollback();
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            DB::rollback();
        }
    }

    private function getMemberAccount($member_id)
    {
        $account = MemberBankAccount::where('member_id', $member_id)
            ->join('banks as b', 'b.id', 'member_bank_accounts.bank_id')
            ->select(
                'b.name as bank_name',
                'b.code as bank_code',
                'member_bank_accounts.account_name as account_name',
                'member_bank_accounts.account_no as account_no'
            )
            ->first();
        return $account;
    }

    private function getTransactionNo()
    {
        $number = date('ymdHis') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $is_exist = TransactionLog::whereBetween('created_at', [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')])
            ->where('transaction_no', $number)->count();
        // 驗證不重覆
        if ($is_exist) {
            return $this->getTransactionNo();
        } else {
            return $number;
        }
    }
}
