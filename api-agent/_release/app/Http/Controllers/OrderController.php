<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Order;
use App\REDIS;
use Validator;

class OrderController extends Controller
{

    /**
     * order/list
     * -訂單列表(只能查看自己的會員)
     *
     * @bodyParam ordery_type_id integer 訂單系統編號 Example: 1
     * @bodyParam currency_id integer 幣別系統編號 Example: 1
     * @bodyParam username string 會員帳號 Example: abc
     * @bodyParam order_no string 訂單編號 Example: 123456
     * @bodyParam status integer 狀態 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "order_no": "訂單編號,ex:16431681",
     *            "username": "會員帳號,ex:test",
     *            "order_type_id": "訂單類別系統編號,ex:2",
     *            "currency_id": "幣種系統編號,ex:1",
     *            "member_id": "會員系統編號,ex:1",
     *            "amount": "金額,ex:100",
     *            "point": "平台點數,ex:100",
     *            "status": "狀態0建立1成功2失敗,ex:1",
     *            "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *            "updated_at": "更新時間,ex:2019-03-08 17:43:28"
     *        },
     *        {
     *            "id": 2,
     *            "order_no": "x16431681",
     *            "username": "test",
     *            "order_type_id": 2,
     *            "currency_id": 1,
     *            "member_id": 3,
     *            "amount": 300,
     *            "point": 300,
     *            "status": 1,
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
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // Redis名稱
            $key = array(
                'order',
                'list',
                $request->order_type_id,
                $request->currency_id,
                $request->username,
                $request->order_no,
                $request->status,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'order',
                'list',
                $request->order_type_id,
                $request->currency_id,
                $request->username,
                $request->order_no,
                $request->status,
                'total'
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $model = $model->leftJoin('members as m', 'm.id', 'orders.member_id');
                $model = $model->where('m.parent', $request->ID);
                if ($request->order_type_id != null) {
                    $model = $model->where('orders.order_type_id', $request->order_type_id);
                }
                if ($request->currency_id != null) {
                    $model = $model->where('orders.currency_id', $request->currency_id);
                }
                if ($request->username != null) {
                    $model = $model->where('m.username', 'like', '%' . $request->username . '%');
                }
                if ($request->order_no != null) {
                    $model = $model->where('orders.order_no', 'like', '%' . $request->order_no . '%');
                }
                if (isset($request->status)) {
                    $model = $model->where('orders.status', $request->status);
                }
                $total = $model->count();
                $data = $model->select('orders.id', 'order_no', 'm.username as username', 'orders.order_type_id', 'orders.currency_id', 'orders.member_id', 'orders.amount', 'orders.point', 'orders.status', 'orders.created_at', 'orders.updated_at')
                    ->groupBy('orders.id')
                    ->orderBy('orders.created_at', 'desc')
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
     * order/view
     * -訂單詳細資料(只能查看自己的會員)
     *
     * @bodyParam id integer required 訂單系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *          "id": "系統編號,ex:1",
     *          "order_type_id": "訂單類別系統編號,ex:2",
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
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'order',
                $request->id
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $data = Order::select('orders.id', 'orders.order_type_id', 'orders.currency_id', 'orders.member_id', 'orders.amount', 'orders.point', 'orders.status', 'orders.created_at', 'orders.updated_at')->leftJoin('members as m', 'm.id', 'orders.member_id')
                    ->where('m.parent', $request->ID)
                    ->where('orders.id', $request->id)
                    ->First();
                if (! $data) {
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

    private function messages()
    {
        return [
            'id.required' => __('error_message.1309'),
            'id.numeric' => __('error_message.1310'),

            'order_type_id.numeric' => __('error_message.1301'),

            'currency_id.numeric' => __('error_message.1302'),

            'member_id.numeric' => __('error_message.1303'),

            'amount.numeric' => __('error_message.1304'),

            'status.in' => __('error_message.1306'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}
