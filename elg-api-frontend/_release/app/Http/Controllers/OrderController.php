<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderType;
use App\REDIS;
use Validator;
use DB;
use App\Models\Member;
use App\Models\TransactionLog;
use App\PaymentYXF;
use App\PaymentNcpay;

/**
 * @group Order
 */
class OrderController extends Controller
{

    /**
     * orders
     * -訂單列表(只能查看自己的訂單記錄)
     *
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "order_type_id": "訂單類別名稱,ex:2",
     *            "currency_id": "幣種系統編號,ex:1",
     *            "member_id": "會員系統編號,ex:1",
     *            "amount": "金額,ex:100",
     *            "point": "平台點數,ex:100",
     *            "balance": "儲值後餘額,ex:100",
     *            "status": "狀態0建立1成功2失敗,ex:1",
     *            "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *            "updated_at": "更新時間,ex:2019-03-08 17:43:28"
     *        },
     *        {
     *            "id": 2,
     *            "order_type_id": 2,
     *            "currency_id": 1,
     *            "member_id": 1,
     *            "amount": 300,
     *            "point": 300,
     *            "balance": 400,
     *            "status": 2,
     *            "created_at": "2019-03-06 16:55:22",
     *            "updated_at": "2019-03-08 17:43:28"
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
            $model = new Order();

            $request->page = isset($request->page) ? $request->page : 1;
            $request->per_page = isset($request->per_page) ? $request->per_page : 50;

            $model = $model->from('orders as o')
                ->join('members as m', 'm.id', 'o.member_id')
                ->join('agents as a', 'a.id', 'm.parent')
                ->join('order_types as ot', 'ot.id', 'o.order_type_id')
                ->leftJoin('order_type_names as otn', function ($join) use ($request) {
                    $join->on('otn.type_id', 'ot.id')
                        ->where('otn.language_id', $request->LANGUAGE);
                })
                ->where('o.member_id', $request->ID)
                ->orderBy('o.created_at', 'DESC')
                ->select(
                    'o.id',
                    DB::raw('IFNULL(otn.name, ot.name) as order_type'),
                    'o.currency_id',
                    'o.member_id',
                    DB::raw('TRUNCATE(o.amount, 2) as amount'),
                    DB::raw('TRUNCATE(o.point, 2) as point'),
                    DB::raw('TRUNCATE(o.balance, 2) as balance'),
                    'o.status',
                    DB::raw("CASE o.status WHEN 0 THEN '" . __('param.create') . "' WHEN 1 THEN '" . __('param.success') . "' WHEN 2 THEN '" . __('param.fail') . "' WHEN 3 THEN '" . __('param.upper_points') . "' END as status_name"),
                    DB::raw('DATE_ADD(o.created_at, INTERVAL 8 HOUR) as created_at'),
                    DB::raw('DATE_ADD(o.updated_at, INTERVAL 8 HOUR) as updated_at')
                );
            $total = $model->count();
            $data = $model
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
     * orders
     * -訂單列表(只能查看自己的訂單記錄)
     *
     * @bodyParam order_type_id integer 狀態 Example: 1
     * @bodyParam start_time dateTime 狀態 Example: 2019-05-01 00:00:00
     * @bodyParam end_time dateTime 狀態 Example: 2019-05-05 23:59:59
     * @bodyParam status integer 狀態 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "order_type_id": "訂單類別名稱,ex:2",
     *            "currency_id": "幣種系統編號,ex:1",
     *            "member_id": "會員系統編號,ex:1",
     *            "amount": "金額,ex:100",
     *            "point": "平台點數,ex:100",
     *            "balance": "儲值後餘額,ex:100",
     *            "status": "狀態0建立1成功2失敗,ex:1",
     *            "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *            "updated_at": "更新時間,ex:2019-03-08 17:43:28"
     *        },
     *        {
     *            "id": 2,
     *            "order_type_id": 2,
     *            "currency_id": 1,
     *            "member_id": 1,
     *            "amount": 300,
     *            "point": 300,
     *            "balance": 400,
     *            "status": 2,
     *            "created_at": "2019-03-06 16:55:22",
     *            "updated_at": "2019-03-08 17:43:28"
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
    public function oldlist(Request $request)
    {
        try {
            $model = new Order();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }
            // Redis名稱
            $key = array(
                'order',
                'list',
                $request->order_type_id,
                $request->start_time,
                $request->end_time,
                $request->status,
                $request->ID,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'order',
                'list',
                $request->order_type_id,
                $request->start_time,
                $request->end_time,
                $request->status,
                $request->ID,
                'total'
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $model = $model->leftJoin('order_types as t', 't.id', 'orders.order_type_id');
                if (isset($request->order_type_id) && $request->order_type_id != '') {
                    $model = $model->where('orders.order_type_id', $request->order_type_id);
                }
                if (isset($request->start_time) && $request->start_time != '') {
                    $model = $model->where('orders.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')));
                }
                if (isset($request->end_time) && $request->end_time != '') {
                    $model = $model->where('orders.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour')));
                }
                if (isset($request->status) && $request->status != '') {
                    $model = $model->where('orders.status', $request->status);
                }
                $model = $model->where('orders.member_id', $request->ID);
                $total = $model->count();
                $data = $model->orderBy('orders.created_at', 'desc')
                    ->select('orders.id', 't.name as order_type_id', 'orders.currency_id', 'orders.member_id', 'orders.amount', 'orders.point', 'orders.balance', 'orders.status', DB::raw('DATE_ADD(orders.created_at,INTERVAL 8 HOUR) as created_at'), DB::raw('DATE_ADD(orders.updated_at,INTERVAL 8 HOUR) as updated_at'))
                    ->orderBy('orders.id', 'desc')
                    ->skip(($request->page - 1) * $request->per_page)
                    ->take($request->per_page)
                    ->get();
                $redis->set($key, $data);
                $redis->set($total_key, $total);
            } else {
                $data = $redis->get($key);
                $total = $redis->get($total_key);
            }
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
     * order
     * - 訂單明細(只能查看自己的訂單記錄)
     *
     * @bodyParam id integer required 訂單系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *          "id": "系統編號,ex:1",
     *          "ordery_type_id": "訂單類別系統編號,ex:2",
     *          "currency_id": "幣種系統編號,ex:1",
     *          "member_id": "會員系統編號,ex:1",
     *          "amount": "金額,ex:100",
     *          "point": "平台點數,ex:100",
     *          "status": "狀態0建立1成功2失敗,ex:1",
     *          "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *          "updated_at": "更新時間,ex:2019-03-08 17:43:28"
     *    }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Order();
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }
            $key = array(
                'order',
                $request->id,
                $request->ID
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                // 只能取自己的訂單記錄
                $data = Order::leftJoin('order_types as t', 't.id', 'orders.order_type_id')->where('orders.member_id', $request->ID)->where('orders.id', $request->id)
                    ->select('orders.id', 't.name as order_type_id', 'orders.currency_id', 'orders.member_id', 'orders.amount', 'orders.point', 'orders.balance', 'orders.status', DB::raw('DATE_ADD(orders.created_at,INTERVAL 8 HOUR) as created_at'), DB::raw('DATE_ADD(orders.updated_at,INTERVAL 8 HOUR) as updated_at'))
                    ->First();
                if (!$data) {
                    return response()->json([
                        'code' => 1307,
                        'message' => __('error_message.1307')
                    ]);
                }
                $redis->set($key, $data);
            } else {
                $data = $redis->get($key);
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
     * order/create
     * - 新增訂單
     *
     * @bodyParam order_type_id integer required 訂單類別系統編號(銀聯) Example: 1
     * @bodyParam amount integer required 金額 Example: 100
     * @bodyParam callback string required 支付後導回哪個網址 Example: http://xxx.xxx.xx
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "支付頁面網址,ex:http://xxx.xxxx/pay",
     *  "order_id": "訂單編號,ex:1123456"
     * }
     */
    public function create(Request $request)
    {
        try {
            $model = new Order();
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()->all()
                ]);
            }
            //查詢玩家目前狀態是否在鎖分中(鎖分模式中禁止開洗分)
            $event_mode = Member::where('id', $request->ID)->pluck('event_mode')->first();
            if ($event_mode == 1) {
                //鎖分模式中禁止開洗分
                return response()->json([
                    'code' => 1934,
                    'message' => __('error_message.1934')
                ]);
            } else {
                $order_id = date('ymdHis') . '_' . str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT) . '_' . $request->order_type_id;
                $data = array();
                $data['order_no'] = $order_id;
                $data['order_type_id'] = $request->order_type_id;
                $data['currency_id'] = '1';
                $data['member_id'] = $request->ID;
                $data['amount'] = $request->amount;
                $data['point'] = $request->amount; // TODO 匯率轉換
                $data['callback'] = $request->callback;
                $data['status'] = 0; // 等金流商那成功或失敗再更改狀態
                $data['admin'] = 0;
                if ($result = $model->create($data)) {
                    // 記錄新增成功後呼叫金流商API回傳支付頁面網址
                    $payment = $this->getPayUrl($result->id, $data);
                    if (is_array($payment) && $payment['code'] == 1000) {
                        // 寫入Redis
                        $key = array(
                            'order',
                            $result->id,
                            $request->ID
                        );
                        $data['id'] = $result->id;
                        $redis = new REDIS();
                        $redis->set($key, $data);
                        // 刪除Redis列表
                        $redis->delete(array(
                            'order',
                            'list'
                        ));
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => $payment['msg'],
                            'order_id' => $order_id
                        ]);
                    } else {
                        return response()->json([
                            'code' => 2004,
                            'message' => $payment
                        ]);
                    }
                } else {
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001')
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
     * order/update
     * - 更新訂單
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "回調網址,ex:http://xxx.xxxx"
     * }
     */
    public function update($id)
    {
        $order = Order::where('status', 0)->find($id);
        // 訂單成功後新增餘額
        if (isset($order)) {
            $member = Member::where('id', $order->member_id);
            $row = $member->first();
            $before_balance = $row->balance;
            $balance = ($row->balance + $order->point);
            Order::find($id)->update([
                'status' => 1,
                'balance' => $balance
            ]);
            $member->update([
                'balance' => DB::raw('balance+' . $order->point)
            ]);
            // 刪除Redis中相關的資料
            $redis = new REDIS();
            $redis->delete(array(
                'member'
            ));
            // 寫入log
            $log = array();
            $log['agent_id'] = $member->parent;
            $log['member_id'] = $order->member_id;
            $log['order_id'] = $id;
            $log['point'] = $order->point;
            $log['before_balance'] = $before_balance;
            $log['balance'] = $balance;
            $log['type'] = 3;
            $log['status'] = 1000;
            TransactionLog::create($log);
        } else {
            return response()->json([
                'code' => 1307,
                'message' => __('error_message.1307')
            ]);
        }

        // 寫入Redis
        $key = array(
            'order',
            $id,
            $order->member_id
        );
        $redis = new REDIS();
        $redis->set($key, $order);
        // 刪除Redis列表
        $redis->delete(array(
            'order'
        ));
        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000'),
            'data' => $order->callback
        ]);
    }

    /**
     * order/callback
     * - TODO 等實際收到資料再改
     */
    public function callback(Request $request)
    {
        $this->log(0, $request->all(), 'callback');
        echo 'success';
    }

    private function getPayUrl($id, $data)
    {
        list($order_no) = explode('_', $data['order_no']);
        // 訂單編號，銀鑫付 16~32字元，NCPay 20字元以內
        $order_no = $order_no . str_pad($id, 7, '0', STR_PAD_LEFT);
        // order 類型，取金流渠道
        $order_type = OrderType::find($data['order_type_id']);
        if (is_object($order_type)) {
            switch ($order_type->cash_flow_id) {
                case 1:
                    // 銀鑫付
                    $payment = new PaymentYXF();
                    break;
                case 2:
                    // NCPay
                    $payment = new PaymentNcpay();
                    break;
            }
            return $payment->deposit($order_no, $data['amount'], $order_type->key);
        }
    }

    private function messages()
    {
        return [
            'id.required' => __('error_message.1309'),
            'id.digits_between' => __('error_message.1310'),

            'order_type_id.required' => __('error_message.1301'),
            'order_type_id.digits_between' => __('error_message.1301'),

            'currency_id.required' => __('error_message.1302'),
            'currency_id.digits_between' => __('error_message.1302'),

            'amount.required' => __('error_message.1304'),
            'amount.numeric' => __('error_message.1304'),

            'point.required' => __('error_message.1305'),
            'point.numeric' => __('error_message.1305'),

            'status.in' => __('error_message.1306'),

            'callback.required' => __('error_message.1313'),
            'callback.url' => __('error_message.1314'),

            'start_time.date_format' => __('error_message.1311'),
            'end_time.date_format' => __('error_message.1312'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}
