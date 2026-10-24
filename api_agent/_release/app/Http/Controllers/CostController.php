<?php
namespace App\Http\Controllers;

use App\Helpers\SQLHelper;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Models\AgentCashFlow;
use App\Models\SmsGatewayInfo;
use Illuminate\Http\Request;
use App\REDIS;
use DB;
use Validator;

class CostController extends Controller
{
    /**
     * cost/list
     * - 列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "code": "代碼,ex:TW",
     *            "name": "預設名稱,ex:Taiwan",
     *            "country_code": "國碼,ex:886",
     *            "phone_rule": "手機驗證正規式,ex:^9\d{8}$",
     *            "status": "狀態0停用1正常,ex:1",
     *            "created_at": "2019-03-06 16:55:22",
     *            "updated_at": "2019-03-08 17:43:28",
     *            "admin": 1
     *        },
     *         {
     *            "id": 3,
     *            "code": "HK",
     *            "name": "Hong Kong",
     *            "country_code": "886",
     *            "phone_rule": "^9\d{8}$",
     *            "status": 1,
     *            "created_at": "2019-03-06 16:56:05",
     *            "updated_at": "2019-03-06 16:56:05",
     *            "admin": 1
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model_game = DB::table('vendor_infos as vi')
                ->join('vendors as v', function ($join) {
                    $join->on('v.id', 'vi.vendor_id')
                        ->where('v.status', 1);
                });
            if ($request->HOST != 1) {
                $model_game = $model_game->join('agent_vendors as av', function ($join) {
                        $join->on('av.vendor_info_id', 'vi.id')
                            ->where('av.status', 1);
                    })
                    ->join('agents as a', function ($join) use ($request) {
                        $join->on('a.id', 'av.agent_id')
                            ->where('a.id', $request->HOST);
                    });
            }
            $model_game = $model_game->where('vi.status', 1)
                ->select(
                    DB::raw('1 as type'),
                    DB::raw('0 as oid'),
                    'v.name as oname',
                    DB::raw(" '' as group_name"),
                    'vi.name',
                    'v.id as main_id',
                    'vi.id as id',
                    DB::raw('0 as group_id'),
                    DB::raw('(vi.cost * 100) AS cost'),
                    'v.status as main_status',
                    'vi.status as status'
                );

            $model_payment = DB::table('agent_cash_flows as acf')
                ->join('cash_flows as cf', function ($join) {
                    $join->on('cf.id', 'acf.cash_flow_id')
                        ->where('cf.status', 1);
                })
                ->join('agent_groups as ag', function ($join) use ($request) {
                    $join->on('ag.id', 'acf.agent_group_id')
                        ->where('ag.agent_group_type', 2);
                })
                ->join('organizations as o', 'o.id', 'ag.organization_id');
            if ($request->HOST != 1) {
                $model_payment = $model_payment->join('agents as a', function ($join) use ($request) {
                    $join->on('a.cash_flow_group_id', 'cf.id')
                        ->where('a.id', $request->HOST);
                });
            }
            $model_payment = $model_payment->where('acf.status', 1)
                ->select(
                    DB::raw('2 as type'),
                    'o.id as oid',
                    'o.name as oname',
                    'ag.name as group_name',
                    'cf.name',
                    'cf.id as main_id',
                    'acf.id as id',
                    'acf.agent_group_id as group_id',
                    DB::raw('(acf.cost * 100) AS cost'),
                    'cf.status as main_status',
                    'acf.status'
                );

            $model_sms = DB::table('sms_gateway_infos as sgi')
                ->join('sms_gateways as sg', function ($join) {
                    $join->on('sg.id', 'sgi.sms_gateway_id')
                        ->where('sg.status', 1);
                })
                ->join('agent_groups as ag', function ($join) use ($request) {
                    $join->on('ag.id', 'sgi.sms_gateway_group_id')
                        ->where('ag.agent_group_type', 3);
                })
                ->join('organizations as o', 'o.id', 'ag.organization_id');
            if ($request->HOST != 1) {
                $model_sms = $model_sms->join('agents as a', function ($join) use ($request) {
                    $join->on('a.sms_gateway_group_id', 'ag.id')
                        ->where('a.id', $request->HOST);
                });
            }
            $model_sms = $model_sms->where('sgi.status', 1)
                ->select(
                    DB::raw('3 as type'),
                    'o.id as oid',
                    'o.name as oname',
                    'ag.name as group_name',
                    'sg.name',
                    'sg.id as main_id',
                    'sgi.id as id',
                    'sgi.sms_gateway_group_id as group_id',
                    'sgi.cost AS cost',
                    'sg.status as main_status',
                    'sgi.status'
                );


            $model = null;
            if ($request->type == 1) {
                $model = $model_game;
                $total = $model_game->getCountForPagination();
            } elseif ($request->type == 2) {
                $model = $model_payment;
                $total = $model_payment->getCountForPagination();
            } elseif ($request->type == 3) {
                $model = $model_sms;
                $total = $model_sms->getCountForPagination();
            } else {
                $model = $model_game->union($model_payment)->union($model_sms);
                $total = DB::table(DB::raw('(' . $model->toSql(). ') as z'))
                    ->mergeBindings($model)
                    ->getCountForPagination();
            }

            // 排序欄位
            $data = $model->orderBy('type', 'asc')
                ->orderBy('group_id', 'asc')
                ->orderBy('main_id', 'asc')
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

    public function setSetting(Request $request)
    {
        try {
            $model = null;
            $cost = $request->cost;
            if ($request->type == 1) {
                $model = VendorInfo::find($request->id);
                $cost = ($request->cost / 100);
            } elseif ($request->type == 2) {
                $model = AgentCashFlow::find($request->id);
                $cost = ($request->cost / 100);
            } elseif ($request->type == 3) {
                $model = SmsGatewayInfo::find($request->id);
            }
            if (is_object($model)) {
                $result = $model->update(['cost' => $cost]);
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000')
                ]);
            } else {
                return response()->json([
                    'code' => 1010,
                    'message' => __('error_message.1010')
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
}
