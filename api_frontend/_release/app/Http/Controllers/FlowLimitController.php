<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\FlowLimit;
use App\Enums\AgentCustomizedSettingKeyEnum;
use Validator;
use DB;

/**
 * @group FlowLimit
 */
class FlowLimitController extends Controller
{

    /**
     * flow_limit/show
     * - 顯示稽核流水
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data":  [
     *        {
     *            "reach": "目前流水,ex:100.00",
     *            "flow_limit": "需要流水,ex:100.00",
     *            "pass": "是否通過,ex:1",
     *            "message": "錯誤訊息,ex:未達稽核流水",
     *        }
     *    ]
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new FlowLimit();
            $data = $model->where('member_id', $request->ID)
                ->where('status', 0)
                ->select(
                    DB::raw('IFNULL(SUM(flow_limit), 0) AS flow_limit'),
                    DB::raw('IFNULL(SUM(reach), 0) AS reach'),
                    DB::raw('CASE WHEN IFNULL(SUM(flow_limit), 0) > IFNULL(SUM(reach), 0) THEN 0 ELSE 1 END AS pass'),
                    DB::raw("'" . __('error_message.1980') . "' AS message")
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
     * flow_limit/list
     * - 稽核列表
     *
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 10
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data":  [
     *        {
     *            "type": "類別,ex:Order Deposit",
     *            "point": "獲得金幣,ex:10.0000",
     *            "flow_limit": "稽核流水,ex:20.0000",
     *            "reach": "有效輸贏流水,ex:5.0000",
     *            "status": "狀態,ex:No Pass",
     *            "created_at": "遊戲商編號,ex:2021-01-01 00:00:00",
     *            "updated_at": "操作時間,ex:2021-01-01 00:00:00"
     *        },
     *        {
     *            "type": "Deposit",
     *            "point": "20.0000",
     *            "flow_limit": "20.0000",
     *            "reach": 20.0000,
     *            "status": "Pass",
     *            "created_at":"2021-01-01 00:00:00",
     *            "updated_at":"2021-01-01 00:00:00"
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
            $data = DB::table('flow_limits AS f')
                ->join('transaction_logs AS t', 't.id', 'f.reference_id')
                ->where('f.member_id', $request->ID);
            $total = $data->count();
            $data = $data->orderBy('f.id', 'desc')
                ->select(
                    DB::raw("CASE f.type WHEN 1 THEN '" . __('param.transaction_type.1') . "' " .
                        "WHEN 2 THEN '" . __('param.transaction_type.2') ."' " .
                        "WHEN 3 THEN '" . __('param.transaction_type.3') ."' " .
                        "WHEN 4 THEN '" . __('param.transaction_type.4') ."' " .
                        "WHEN 5 THEN '" . __('param.transaction_type.5') ."' " .
                        "WHEN 6 THEN '" . __('param.transaction_type.6') ."' " .
                        "WHEN 7 THEN '" . __('param.transaction_type.7') ."' " .
                        "WHEN 8 THEN '" . __('param.transaction_type.8') ."' " .
                        "WHEN 9 THEN '" . __('param.transaction_type.9') ."' " .
                        "WHEN 10 THEN '" . __('param.transaction_type.10') ."' " .
                        "WHEN 11 THEN '" . __('param.transaction_type.11') ."' " .
                        "WHEN 12 THEN '" . __('param.transaction_type.12') ."' " .
                        "WHEN 13 THEN '" . __('param.transaction_type.13') ."' " .
                        "WHEN 14 THEN '" . __('param.transaction_type.14') ."' " .
                        "WHEN 15 THEN '" . __('param.transaction_type.15') ."' " .
                        "WHEN 16 THEN '" . __('param.transaction_type.16') ."' " .
                        "WHEN 17 THEN '" . __('param.transaction_type.17') ."' " .
                        "WHEN 18 THEN '" . __('param.transaction_type.18') ."' " .
                        "WHEN 19 THEN '" . __('param.transaction_type.19') ."' " .
                        "WHEN 20 THEN '" . __('param.transaction_type.20') ."' " .
                        "WHEN 21 THEN '" . __('param.transaction_type.21') ."' " .
                        "ELSE '" . __('param.other')."' END AS type"),
                    't.point',
                    'f.flow_limit',
                    'f.reach',
                    DB::raw("CASE f.status WHEN 1 THEN '" . __('param.success') . "' " .
                        "WHEN 9 THEN '" . __('param.cancel_flow_limit') ."' " .
                        "ELSE '" . __('param.no_pass')."' END AS status"),
                    DB::raw('DATE_ADD(f.created_at, INTERVAL 8 HOUR) AS created_at'),
                    DB::raw('DATE_ADD(f.updated_at, INTERVAL 8 HOUR) AS updated_at')
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

    /** 稽核流水檢查 */
    public function checkFlowLimit($member_id)
    {
        $data = DB::table('members AS m')
            ->join('agents as a', 'a.id', 'm.parent')
            ->leftJoin('agent_customized_settings as s', function ($join) {
                $join->on('s.agent_group_id', 'a.customized_setting_group_id')
                    ->where('s.setting_key', AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE);
            })
            ->leftJoin('flow_limits AS f', function ($join) {
                $join->on('f.member_id', 'm.id')
                    ->where('f.status', 0);
            })
            ->where('m.id', $member_id)
            ->select(
                DB::raw('IFNULL(SUM(f.flow_limit), 0) AS flow_limit'),
                DB::raw('IFNULL(SUM(f.reach), 0) AS reach'),
                DB::raw('IFNULL(s.value, 0) AS flow_limit_visable')
            )
            ->first();
        if ($data->flow_limit_visable == 1 && $data->flow_limit > $data->reach) {
            return true;
        } else {
            return false;
        }
    }
}
