<?php

namespace App\Http\Controllers;

use App\Models\AgentGroup;
use target\support\Models\TransactionLog;
use Illuminate\Http\Request;
use App\Models\Vendor;
use App\REDIS;
use App\Helpers\Common;
use App\Models\TransferLimit;
use App\Models\TransferLimitLog;
use Validator;
use DB;
use App\AES;
use log;

class TransferLimitController extends Controller
{

    public function list(Request $request)
    {
        try {
            $model = DB::table('transfer_limits as tl')
                ->join('agent_groups as ag', 'ag.id', 'tl.agent_group_id');
            if (isset($request->agent_group_name) && $request->agent_group_name != '') {
                $model = $model->where('ag.name', 'like', '%' . $request->agent_group_name . '%');
            }
            $total = $model->count();

            $data = $model->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    'tl.id as id',
                    'ag.name as name',
                    'tl.limit as limit'
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

    public function getLimitInfo(Request $request)
    {
        try {
            $data = DB::table('transfer_limits as tl')
                ->join('agent_groups as ag', 'ag.id', 'tl.agent_group_id')
                ->select(
                    'tl.id as id',
                    'ag.name as name',
                    'tl.limit as limit'
                )
                ->where('tl.id', $request->limit_id)
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

    public function updateLimit(Request $request)
    {
        try {
            $transferLimit = TransferLimit::find($request->id);

            $beforeLimit = $transferLimit->limit;
            $amount = $request->type == 0 ? $request->amount : (-1 * $request->amount);
            $afterLimit = $beforeLimit + $amount;
            if($afterLimit < 0){
                return response()->json([
                    'code' => 1022,
                    'message' => __('error_message.1022'),
                ]);
            }
            $data = array();
            $data['agent_group_id'] = $request->id;
            $data['amount'] = $amount;
            $data['before_limit'] = $beforeLimit;
            $data['after_limit'] = $afterLimit;
            $data['memo'] = $request->memo;

            if ($transferLimit->update(array('limit' => $afterLimit))) {
                $transferLimitLog = new TransferLimitLog();
                if ($transferLimitLog->create($data)) {
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

    public function getDropDownList(Request $request)
    {
        try {
            $data = DB::table('agent_groups')
                ->where('agent_group_type', 1)
                ->select('id', 'name')
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

    public function createLimit(Request $request)
    {
        try {
            $model = new TransferLimit();
            $data = array();
            $data['agent_group_id'] = $request->id;
            $data['limit'] = $request->limit;

            if ($model->create($data)) {
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

    public function getRecord(Request $request)
    {
        try {
            $model = TransferLimitLog::join('agent_groups as ag', 'ag.id', 'transfer_limit_logs.agent_group_id')
                ->where('transfer_limit_logs.created_at', '>=', $request->start_time)
                ->where('transfer_limit_logs.created_at', '<=', $request->end_time);;

            if ($request->type != '' && $request->type == 1) {
                $model = $model->where('transfer_limit_logs.amount', '>', 0);
            } else if ($request->type != '' && $request->type == 2) {
                $model = $model->where('transfer_limit_logs.amount', '<', 0);
            }

            if (isset($request->agent_group_id) && $request->agent_group_id !== '0') {
                $model = $model->where('ag.id', $request->agent_group_id);
            }

            $total = $model->count();

            $data = $model->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->orderBy('transfer_limit_logs.created_at', 'DESC')
                ->select(
                    'ag.name as agent_group_name',
                    'transfer_limit_logs.amount as amount',
                    'transfer_limit_logs.before_limit as before_limit',
                    'transfer_limit_logs.after_limit as after_limit',
                    'transfer_limit_logs.memo as memo',
                    'transfer_limit_logs.created_at as created_at'
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
}