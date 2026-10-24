<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Order;
use App\TransactionLog;
use App\Member;
use App\REDIS;
use Validator;
use DB;
use App\Agent;

class ReportController extends Controller
{

    /**
     * report/deposit
     * -儲值報表
     *
     * @bodyParam agent_id integer required 營運商系統編號 Example: 1
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "type": "支付方式編號,ex:1",
     *            "total": "總金額,ex:100",
     *            "date": "日期,ex:2019-04-01"
     *        },
     *        {
     *            "type": 1,
     *            "total": 200,
     *            "date": "2019-04-02"
     *        }
     *    ]
     * }
     */
    public function deposit(Request $request)
    {
        try {
            $model = new Order();
            $validator = Validator::make($request->all(), $this->rule(), $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // Redis名稱
            $model = $model->leftJoin('members as m', 'm.id', '=', 'orders.member_id');
            if ($request->agent_id != null) {
                $model = $model->where('parent', $request->agent_id);
            }
            $model = $model->where('orders.created_at', '>=', $request->start_date . ' 00:00:00');
            $model = $model->where('orders.created_at', '<=', $request->end_date . ' 23:59:59');
            $model = $model->where('orders.status', 1);
            $data = $model->select(DB::raw('orders.order_type_id as type,sum(orders.amount) as total,DATE(orders.created_at) as date'))
                ->groupBy(DB::raw('orders.order_type_id,DATE(orders.created_at)'))
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
     * report/transaction
     * -轉點報表
     *
     * @bodyParam agent_id integer required 營運商系統編號 Example: 1
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "type": "支付方式編號,ex:1",
     *            "total": "總金額,ex:100",
     *            "date": "日期,ex:2019-04-01"
     *        },
     *        {
     *            "type": 1,
     *            "total": 200,
     *            "date": "2019-04-02"
     *        }
     *    ]
     * }
     */
    public function transaction(Request $request)
    {
        try {
            $model = new TransactionLog();
            $validator = Validator::make($request->all(), $this->rule(), $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // Redis名稱
            $model = $model->leftJoin('members as m', 'm.id', '=', 'transaction_logs.member_id');
            if ($request->agent_id != null) {
                $model = $model->where('parent', $request->agent_id);
            }
            $model = $model->where('transaction_logs.created_at', '>=', $request->start_date . ' 00:00:00');
            $model = $model->where('transaction_logs.created_at', '<=', $request->end_date . ' 23:59:59');
            $model = $model->where('transaction_logs.status', 1000);
            $data = $model->select(DB::raw('transaction_logs.type,sum(transaction_logs.point) as total,DATE(transaction_logs.created_at) as date'))
                ->groupBy(DB::raw('transaction_logs.type,DATE(transaction_logs.created_at)'))
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
     * report/register
     * -註冊報表
     *
     * @bodyParam agent_id integer required 營運商系統編號 Example: 1
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "total": "總人數,ex:100",
     *            "date": "日期,ex:2019-04-01"
     *        },
     *        {
     *            "total": 200,
     *            "date": "2019-04-02"
     *        }
     *    ]
     * }
     */
    public function register(Request $request)
    {
        try {
            $model = new Member();
            $validator = Validator::make($request->all(), $this->rule(), $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            if ($request->agent_id != null) {
                $model = $model->where('parent', $request->agent_id);
            }
            $model = $model->where('created_at', '>=', $request->start_date . ' 00:00:00');
            $model = $model->where('created_at', '<=', $request->end_date . ' 23:59:59');
            $data = $model->select(DB::raw('count(id) as total,DATE(created_at) as date'))
                ->groupBy(DB::raw('DATE(created_at)'))
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
     * report/numberOfPeople
     * -營運商下線/會員人數
     *
     * @bodyParam agent_id integer required 營運商系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *            "agent": "下線人數,ex:10",
     *            "member": "會員人數,ex:200"
     *   }
     * }
     */
    public function numberOfPeople(Request $request)
    {
        try {
            $rules = array(
                'agent_id' => 'required|digits_between:0,10'
            );
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $agents = Agent::where('parent', $request->agent_id)->count();
            $members = Member::where('parent', $request->agent_id)->count();
            $data = [
                "agent" => $agents,
                "member" => $members
            ];
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

    private function rule()
    {
        return [
            'agent_id' => 'digits_between:0,10',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after:start_date'
        ];
    }

    private function messages()
    {
        return [
            'agent_id.required' => __('error_message.2017'),
            'agent_id.digits_between' => __('error_message.2018'),

            'start_date.required' => __('error_message.2011'),
            'start_date.date_format' => __('error_message.2013'),

            'end_date.required' => __('error_message.2012'),
            'end_date.date_format' => __('error_message.2014'),
            'end_date.after' => __('error_message.2015')
        ];
    }
}
