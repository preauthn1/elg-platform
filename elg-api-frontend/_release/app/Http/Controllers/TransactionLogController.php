<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use App\Models\Member;
use App\Models\TransactionLog;
use Validator;
use DB;

/**
 * @group Transaction
 * - 帳變記錄
 */
class TransactionLogController extends Controller
{

    /**
     * transaction/redEnvelope
     * - 紅包記錄列表
     *
     * @bodyParam type integer required 類型1:發送記錄2:領取記綠3:用戶手動退回OR系統退還
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "created_at": "時間,ex:2019-01-01 00:00:00",
     *            "nickname": "好友,ex:Joker",
     *            "point": "平台點數,ex:100",
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
    public function redEnvelope(Request $request)
    {
        try {
            $model = new TransactionLog();
            $validator = Validator::make($request->all(), $model->rules_red_envelope, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $model = DB::table('transaction_logs as l');
            switch ($request->type) {
                case 1:
                    $model = $model->leftJoin('members as m', 'm.id', 'l.member_id')->where('l.type', 6);
                    break;
                case 2:
                    $model = $model->leftJoin('members as m', 'm.username', 'l.operator')->where('l.type', 7);
                    break;
                case 3:
                    $model = $model->leftJoin('members as m', 'm.id', 'l.member_id')->whereIn('l.type', [8, 9]);
                    break;
                default:
                    $model = $model->leftJoin('members as m', 'm.id', 'l.member_id')->where('l.type', 6);
                    break;
            }
            $model = $model->select('l.id', DB::raw('DATE_ADD(l.created_at,INTERVAL 8 HOUR) as created_at'), 'm.nickname', 'l.point')
                ->where('l.member_id', $request->ID)
                ->where('l.status', 1000); //1000是成功
            $total = $model->count();
            $data = $model
                ->orderBy('l.created_at', 'desc')
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
     * transaction/customerService
     * - 客服轉帳記錄列表
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
     *            "transactioin_no": "單號,ex:19090100001",
     *            "created_at": "時間,ex:2019-01-01 00:00:00",
     *            "type": "類型1:轉入2:轉出,ex:1",
     *            "point": "平台點數,ex:100"
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
    public function customerService(Request $request)
    {
        try {
            $model = new TransactionLog();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            $model = $model->whereIn('type', [4, 5, 10, 11, 12])->where('member_id', $request->ID)->where('status', 1000); //1000是成功
            $total = $model->count();
            $type = DB::raw('(CASE type WHEN 4 THEN 1 WHEN 5 THEN 2 WHEN 10 THEN 1 WHEN 11 THEN 1 WHEN 12 THEN 1 END) as type');
            $data = $model->select('id', 'transaction_no', DB::raw('DATE_ADD(created_at,INTERVAL 8 HOUR) as created_at'), $type, 'point')
                ->orderBy('created_at', 'desc')
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

    public function insertLog($data)
    {
        // 寫入轉點LOG
        $log = array();
        $log['transaction_no'] = $this->getTransactionNo();
        $log['agent_id'] = $data['agent_id'];
        $log['member_id'] = $data['member_id'];
        $log['vendor_id'] = isset($data['vendor_id']) ? $data['vendor_id'] : 0;
        $log['type'] = $data['type'];
        $log['point'] = $data['point'];
        $log['tax'] = isset($data['tax']) ? $data['tax'] : 0;
        $log['vendor_point'] = isset($data['vendor_point']) ? $data['vendor_point'] : 0;
        $log['rate'] = isset($data['rate']) ? $data['rate'] : 0;
        $log['before_balance'] = $data['before_balance'];
        $log['balance'] = $data['balance'];
        $log['vendor_balance'] = isset($data['vendor_balance']) ? $data['vendor_balance'] : 0;
        $log['status'] = $data['status'];
        $log['remark'] = isset($data['remark']) ? $data['remark'] : '';
        $log['result'] = isset($data['result']) ? $data['result'] : '';
        $log['operator'] =  isset($data['operator']) ? $data['operator'] : '';
        $log['admin'] = $data['member_id'];
        $log['checksum'] = $this->getTransactionLogChecksum(
            $log['member_id'],
            $log['transaction_no'],
            $log['vendor_id'],
            $log['type'],
            $log['point'],
            $log['balance'],
            $log['status'],
            $log['remark'],
            $log['result']
        );
        return TransactionLog::create($log);
    }

    public function getTransactionLogChecksum($memberId, $transactionNo, $vendorId, $type, $point, $balance, $status, $remark, $result)
    {
        return md5($memberId . $transactionNo . $vendorId . $type . $point . $balance . $status . $remark . $result);
    }
    
    private function getTransactionNo()
    {
        $number = date('ymdHis') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $is_exist = TransactionLog::whereBetween('created_at', [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')])
            ->where('transaction_no', $number)
            ->count();
        // 驗證不重覆
        if ($is_exist) {
            return $this->getTransactionNo();
        } else {
            return $number;
        }
    }

    private function rule()
    {
        return [
            'vendor_id' => 'required|digits_between:0,10',
            'point' => array(
                'required',
                'regex:/(^[1-9][0-9]*$)/u'
            )
        ];
    }

    private function messages()
    {
        return [
            'new_password.required' => __('error_message.1019'),
            'new_password.r_alpha_num' => __('error_message.2034'),
            'new_password.between' => __('error_message.1020'),

            'game_id.required' => __('error_message.1414'),
            'game_id.digits_between' => __('error_message.1414'),

            'vendor_id.required' => __('error_message.1415'),
            'vendor_id.digits_between' => __('error_message.1416'),

            'game_type.required' => __('error_message.1404'),

            'start_time.date_format' => __('error_message.1311'),
            'end_time.date_format' => __('error_message.1312'),

            'point.required' => __('error_message.2026'),
            'point.regex' => __('error_message.2026'),

            'status.in' => __('error_message.2033'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}
