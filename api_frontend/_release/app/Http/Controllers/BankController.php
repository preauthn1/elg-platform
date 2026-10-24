<?php

namespace App\Http\Controllers;

use App\Models\WithdrawalOrder;
use App\REDIS;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderImage;
use App\Models\BankAccount;
use App\Models\Member;
use DB;


class BankController extends Controller
{

    public function hasAutoDespoiste(Request $request)
    {
        try {
            $result = Member::where('members.id', $request->ID)
                ->join('agents as a', 'a.id', 'members.parent')
                ->join('agent_groups as ag', 'ag.id', 'a.cash_flow_group_id')
                ->join('agent_cash_flows as acf', function ($join) {
                    $join->on('acf.agent_group_id', 'ag.id')
                        ->where('acf.status', 1)
                        ->where('acf.cash_flow_id', 5);
                })
                ->join('cash_flows as cf', function ($join) {
                    $join->on('cf.id', 'acf.cash_flow_id')
                        ->where('cf.type', 2);
                })
                ->select('acf.*')
                ->first();
            if (is_object($result)) {
                $is_open = 1;
            } else {
                $is_open = 0;
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $is_open,
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
     * bank/getAccount
     * - 取得銀行
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "type": "有無訂單,ex:1",
     *            "bank_id": "銀行帳戶編號, ex:1"
     *            "bank_name": "銀行名稱,ex:XX銀行",
     *            "bank_code": "銀行代碼,ex:001",
     *            "account_name": "帳戶名稱,ex:abc111",
     *            "account_no": "銀行帳戶,ex:123456789"
     *        }
     *    ]
     * }
     */
    public function show(Request $request)
    {
        try {
            $data = array();
            //查詢此玩家有無以建立訂單，有的話取得訂單的銀行資料，沒有的話就給他一組銀行資料
            $model = $this->checkOrder($request->ID);
            $order = $model->select('orders.id as order_id', 'oi.bank_id as bank_id')->where('orders.status', 0)->first();
            $info = $this->getInfo($request);
            if (!is_object($order)) {
                $account = $this->getBankAccount($request->ID);
//                BankAccount::where('account_no', $account->account_no)->increment('use_times');
                $data['type'] = 1;
            } else {
                $account = $this->getBankAccount($request->ID, $order->bank_id);
                //如我銀行是關閉的，就刪除訂單並給他新的銀行帳號
                if (count($account) > 0) {
                    $data['type'] = 2;
                } else {
                    Order::find($order->order_id)->update(["status" => 2]);
                    $account = $this->getBankAccount($request->ID);
//                    BankAccount::where('account_no', $account->account_no)->increment('use_times');
                    $data['type'] = 1;
                }
            };
            $data['bank'] = $account;
//            $data['bank_id'] = $account->bank_id;
//            $data['bank_name'] = $account->bank_name;
//            $data['bank_code'] = $account->bank_code;
//            $data['account_name'] = $account->account_name;
//            $data['account_no'] = $account->account_no;
//            $data['min_amount'] = $account->min_amount;
//            $data['max_amount'] = $account->max_amount;

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
     * bank/order
     * - 取得/創建訂單
     *
     * @bodyParam bank_id integer 銀行帳戶編號 Example: 1
     * @bodyParam amount integer 金額 Example: 100
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "order_id": "訂單id,ex:1",
     *            "order_no": "訂單編號,ex:202009174243713167",
     *            "bank_name": "銀行名稱,ex:XX銀行",
     *            "bank_code": "銀行代碼,ex:001",
     *            "account_name": "帳戶名稱,ex:abc123"
     *            "account_no": "銀行帳戶,ex:123456789"
     *            "amount": "金額,ex:100"
     *            "status": "狀態,ex:1"
     *        }
     *    ]
     * }
     */
    public function order(Request $request)
    {
        try {
            $data = array();
            $model = $this->checkOrder($request->ID);
            $order = $model->select(
                'oi.bank_id as bank_id',
                'orders.id as id',
                'orders.order_no as order_no',
                'orders.amount as amount',
                'orders.status as status'
            )
                ->first();
            if (isset($request->bank_id) && isset($request->amount)) {
                if (!is_object($order)) {
                    //再創新訂單前要確保沒有未完結的單
                    $agentCashFlow = $this->getAgentCashFlowId($request->ID);
                    $bank = $this->getBankAccount($request->ID, $request->bank_id);
                    if ($request->amount < $bank['0']->min_amount || $request->amount > $bank['0']->max_amount) {
                        return response()->json([
                            'code' => 9997,
                            'message' => __('error_message.9997')
                        ]);
                    }
                    $point = $request->amount * $agentCashFlow->rate;
                    $createData = [
                        'agent_cash_flow_id' => $agentCashFlow->agent_cash_flow_id,
                        'order_type_id' => $bank['0']->order_type_id,
                        'currency_id' => 1,
                        'member_id' => $request->ID,
                        'amount' => $request->amount,
                        'point' => $point,
                        'balance' => 0,
                        'status' => 0,
                    ];
                    $try = 0;
                    do {
                        $createData['order_no'] = $this->getOrderNo();
                        $model = Order::create($createData);
                        $try++;
                    } while (!$model && $try < 3); // 最多嘗試三次創建訂單

                    if ($model) {
                        $ImageData = [
                            'order_id' => $model->id,
                            'bank_id' => $request->bank_id,
                            'image' => ' '
                        ];
                        $OrderImage = OrderImage::create($ImageData);
                        if ($OrderImage) {
                            $data['order_id'] = $model->id;
                            $data['order_no'] = $model->order_no;
                            $data['bank_name'] = $bank['0']->bank_name;
                            $data['bank_code'] = $bank['0']->bank_code;
                            $data['account_name'] = $bank['0']->account_name;
                            $data['account_no'] = $bank['0']->account_no;
                            $data['amount'] = $request->amount;
                            $data['status'] = 0;
                        }
                    }
                } else {
                    return response()->json([
                        'code' => 2004,
                        'message' => __('error_message.2004')
                    ]);

                }
            } else {
                $bank = $this->getBankAccount($request->ID, $order->bank_id);
                $data['order_id'] = $order->id;
                $data['order_no'] = $order->order_no;
                $data['bank_name'] = $bank['0']->bank_name;
                $data['bank_code'] = $bank['0']->bank_code;
                $data['account_name'] = $bank['0']->account_name;
                $data['account_no'] = $bank['0']->account_no;
                $data['amount'] = $order->amount;
                $data['status'] = $order->status;
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
     * bank/delete
     * - 刪除訂單
     *
     * @bodyParam order_id integer 銀行代碼 Example: 12
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */

    public function delete(Request $request)
    {
        try {
            $model = new Order();
            if ($model->find($request->order_id)) {
                if ($model->find($request->order_id)->update(array('status' => 2))) {
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
                return response()->json([
                    'code' => 1307,
                    'message' => __('error_message.1307')
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
     * bank/uploadImage
     * - 上傳匯款證明
     *
     * @bodyParam order_id integer 銀行代碼 Example: 12
     * @bodyParam image string base64 file 匯款證明
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */

    public function uploadImage(Request $request)
    {
        try {
            $model = new Order();
            $order = $model->find($request->order_id);
            if (is_object($order)) {
                $path = $this->uploadBase64File($request->image);
                if ($path['code'] != 1000) {
                    return response()->json([
                        'code' => $path['code'],
                        'message' => $path['message']
                    ]);
                }
                if (OrderImage::where('order_id', $request->order_id)->update(['image' => $path['message']])) {
                    $updateOrder = Order::find($request->order_id);
                    //成功建立訂單後,redis增加未處理的訂單數
                    if ($updateOrder->update(['status' => 1])) {
                        $this->addDepositRedis($updateOrder->agent_cash_flow_id, $updateOrder->id);
                        $data = [
                            'order_no' => $order->order_no,
                            'amount' => (int)$order->amount
                        ];
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => $data
                        ]);
                    } else {
                        return response()->json([
                            'code' => 2002,
                            'message' => __('error_message.2002'),
                        ]);
                    }
                }
            } else {
                return response()->json([
                    'code' => 1307,
                    'message' => __('error_message.1307')
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json(['code' => $info[0],
                'message' => $info[2]]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function getBankAccount($member_id, $bank_id = '')
    {
        $data = DB::table('bank_accounts as ba')
            ->join('order_types as ot', function ($join) {
                $join->on('ot.id', 'ba.order_type_id')
                    ->where('ot.status', 1);
            })
            ->join('cash_flows as cf', function ($join) {
                $join->on('cf.id', 'ot.cash_flow_id')
                    ->where('cf.id', 5);
            })
            ->join('agent_cash_flow_order_types as acfot', 'acfot.order_type_id', 'ot.id')
            ->join('agent_cash_flows as acf', 'acf.id', 'acfot.agent_cash_flow_id')
            ->join('agent_groups as ag', 'ag.id', 'acf.agent_group_id')
            ->join('agents as a', 'a.cash_flow_group_id', 'ag.id')
            ->join('members as m', function ($join) use ($member_id) {
                $join->on("m.parent", "a.id")
                    ->where("m.id", $member_id);
            })
            ->select(
                'ot.id as order_type_id',
                'ba.id as bank_id',
                'ot.name as bank_name',
                'ot.key as bank_code',
                'ba.account_name as account_name',
                'ba.account_no as account_no',
                DB::raw('TRUNCATE(ba.min_amount, 0) as min_amount'),
                DB::raw('TRUNCATE(ba.max_amount, 0) as max_amount')
            )
            ->where('ba.status', 1);
        if ($bank_id != '') {
            $data = $data->where('ba.id', $bank_id);
        }
        $account = $data->get();
        return $account;
    }

    private function getOrderNo()
    {
        $prefix = date('Ymd');
        $orderNo = $prefix
            . random_int(100000, 999999)
            . substr(sprintf("%01.4f", microtime(true)), -4);
        return $orderNo;
    }

    private function checkOrder($member_id)
    {
        $model = Order::where('member_id', $member_id)
            ->join('members as m', 'orders.member_id', 'm.id')
            ->join('agents as a', 'a.id', 'm.parent')
            ->join('agent_groups as ag', 'ag.id', 'a.cash_flow_group_id')
            ->join('agent_cash_flows as acf', function ($join) {
                $join->on("acf.agent_group_id", "ag.id")
                    ->on('acf.id', 'orders.agent_cash_flow_id');
            })
            ->join('cash_flows as cf', function ($join) {
                $join->on('cf.id', 'acf.cash_flow_id')
                    ->where('cf.type', 2);
            })
            ->join('order_images as oi', 'oi.order_id', 'orders.id')
            ->where('orders.status', 0)
            ->orderBy('orders.id', 'desc');

        return $model;
    }

    private function getAgentCashFlowId($member_id)
    {
        $result = DB::table("members as m")
            ->join('agents as a', 'a.id', 'm.parent')
            ->join('agent_groups as ag', 'ag.id', 'a.cash_flow_group_id')
            ->join('agent_cash_flows as acf', 'acf.agent_group_id', 'ag.id')
            ->join('cash_flows as cf', function ($join) {
                $join->on('cf.id', 'acf.cash_flow_id')
                    ->where('cf.type', 2);
            })
            ->where('m.id', $member_id)
            ->select('acf.id as agent_cash_flow_id', 'acf.rate as rate')
            ->first();

        return $result;
    }

    private function getInfo($request)
    {
        $data = DB::table('agent_cash_flows as acf')
            ->join('agent_groups as ag', 'ag.id', 'acf.agent_group_id')
            ->join('agents as a', 'a.cash_flow_group_id', 'ag.id')
            ->join('members as m', function ($join) use ($request) {
                $join->on('m.parent', 'a.id')
                    ->where('m.id', $request->ID);
            })
            ->join('cash_flows as cf', function ($join) {
                $join->on('cf.id', 'acf.cash_flow_id')
                    ->where('cf.type', 2);
            })
            ->select('acf.*')
            ->first();
        $result = json_decode($data->info);

        return $result;
    }

    private function addDepositRedis($agent_cash_flow_id, $order_id)
    {
        $redis = new REDIS();
        $key = array(
            'notice',
            'deposit',
            $agent_cash_flow_id
        );
        //如果沒有資料,就查DB做寫入
        if (!$redis->exists($key)) {
            $model = Order::where('agent_cash_flow_id', $agent_cash_flow_id)
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
                'amount' => $data->amount++,
                'last_order_id' => $order_id
            );
            $redis->setCommon($key, $data);
        }
    }

}
