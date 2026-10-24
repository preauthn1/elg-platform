<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\SmsGateway;
use App\Models\SmsGatewayCountry;
use App\Models\SmsGatewayGroupDetail;
use App\Models\SmsGatewayInfo;
use App\Models\SmsGatewayInfoCountry;
use App\Models\SmsSendRecord;
use App\Models\SmsText;
use App\Models\AgentGroup;
use App\Helpers\Common;
use App\REDIS;
use Hash;
use Validator;
use DB;
use log;

class SmsController extends Controller
{

    /**
     * sms/dropdown
     * - 簡訊商下拉選單
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "簡訊商名稱,ex:1Code",
     *            "code": "代碼,ex:OC",
     *            "info_status": "狀態; 0:停用, 1:啟用 ex:1",
     *            "status": "sms_gateway_infos 狀態; 0:停用, 1:啟用 ex:1"
     *        }
     *    ]
     * }
     */
    public function dropdownList (Request $request)
    {
        try {
            $key = array(
                'sms',
                'dropdownlist',
                $request->HOST
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $model = DB::table('agents as a')
                    ->join('sms_gateway_infos as sgi', 'sgi.sms_gateway_group_id', 'a.sms_gateway_group_id')
                    ->join('sms_gateways as sg', 'sg.id', 'sgi.sms_gateway_id');
                if ($request->HOST != 1) {
                    $model = $model->where('a.id', $request->HOST);
                }
                $data = $model->groupBy('sg.id')
                    ->orderBy('sgi.status', 'DESC')
                    ->orderBy('sgi.sort', 'ASC')
                    ->select(
                        'sg.id',
                        'sg.name',
                        'sg.code',
                        'sg.status as info_status',
                        'sgi.status'
                    )
                    ->get();

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
     * sms/gateway/list
     * - 簡訊商列表
     *
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "簡訊商名稱,ex:1Code",
     *            "code": "代碼,ex:OC",
     *            "info": "簡訊商參數資料(json), ex: {'abc':'123'}",
     *            "url": "API URL, ex: http://www.abc.ocm",
     *            "memo": "API URL, ex: http://www.abc.ocm",
     *            "status": "sms_gateway_infos 狀態; 0:停用, 1:啟用 ex:1",
     *            "admin": "修改人員ID; ex: 1",
     *            "country_code": "國家代碼;",
     *            "country_name": "國家名稱;"
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
    public function getGatewayList(Request $request)
    {
        try {
            $model = DB::table('sms_gateways as sg')
                ->leftJoin('sms_gateway_countries as sgc', function ($join) {
                    $join->on('sgc.sms_gateway_id', 'sg.id')
                        ->where('sgc.status', 1);
                })
                ->leftJoin('countries as c', function ($join) {
                    $join->on('c.id', 'sgc.country_id')
                        ->where('c.status', 1);
                })
                ->leftJoin('country_names as cn',function ($join) use ($request) {
                    $join->on('cn.country_id', 'c.id')
                        ->where('cn.language_id', $request->LANGUAGE);
                })
                ->groupBy('sg.id')
                ->orderBy('sg.id', 'ASC')
                ->orderBy('c.id', 'ASC');

            $total = $model->count();

            $data = $model->orderBy('status', 'DESC')
                ->select(
                    'sg.id',
                    'sg.name',
                    'sg.code',
                    'example.invalid',
                    'sg.url',
                    'sg.memo',
                    'sg.status',
                    'sg.admin',
                    DB::raw('GROUP_CONCAT(LOWER(c.code)) as country_code'),
                    DB::raw("GROUP_CONCAT(IFNULL(cn.name, c.name), ' (', c.country_code,')') as country_name")
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
     * sms/gateway/show
     * - 簡訊商資訊
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "簡訊商名稱,ex:1Code",
     *            "code": "代碼,ex:OC",
     *            "info": "簡訊商參數資料(json), ex: {'abc':'123'}",
     *            "url": "API URL, ex: http://www.abc.ocm",
     *            "memo": "API URL, ex: http://www.abc.ocm",
     *            "status": "sms_gateway_infos 狀態; 0:停用, 1:啟用 ex:1",
     *            "admin": "修改人員ID; ex:1",
     *            "country_code": "國家代碼;",
     *            "sms_gateway_countries_id": "sms_gateway_countries.id",
     *            "country_id": "國家ID;"
     *        }
     *    ]
     * }
     */
    public function getGateway(Request $request)
    {
        try {
            $model = DB::table('sms_gateways as sg')
                ->leftJoin('sms_gateway_countries as sgc', function ($join) {
                    $join->on('sgc.sms_gateway_id', 'sg.id')
                        ->where('sgc.status', 1);
                })
                ->leftJoin('countries as c', function ($join) {
                    $join->on('c.id', 'sgc.country_id')
                        ->where('c.status', 1);
                })
                ->where('sg.id', $request->id);

            $data = $model->groupBy('sg.id')
                ->orderBy('sg.id', 'ASC')
                ->orderBy('c.id', 'ASC')
                ->select(
                    'sg.id',
                    'sg.name',
                    'sg.code',
                    'example.invalid',
                    'sg.url',
                    'sg.memo',
                    'sg.status',
                    'sg.admin',
                    DB::raw('GROUP_CONCAT(c.code) as country_code'),
                    DB::raw('GROUP_CONCAT(sgc.id) as sms_gateway_countries_id'),
                    DB::raw('GROUP_CONCAT(c.id) as country_id')
                )
                ->first();
;
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
     * sms/gateway/save
     * - 新增/修改簡訊商資訊
     *
     * @bodyParam code string 簡訊商代碼 Example: sg
     * @bodyParam name string 簡訊商名稱 Example: sms-get
     * @bodyParam url string API Url Example: http://example.invalid/
     * @bodyParam memo string 備註 Example: memo
     * @bodyParam info string 簡訊商參數資料(json) Example: {"username":{"type":"text"}}
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "簡訊商名稱,ex:1Code",
     *            "code": "代碼,ex:OC",
     *            "info": "簡訊商所需參數資料(json), ex: {'abc':'123'}",
     *            "url": "API URL, ex: http://www.abc.ocm",
     *            "memo": "API URL, ex: http://www.abc.ocm",
     *            "status": "sms_gateway_infos 狀態; 0:停用, 1:啟用 ex:1",
     *            "admin": "修改人員ID; ex:1"
     *        }
     *    ]
     * }
     */
    public function modifyGateway(Request $request)
    {
        try {
            $model = new SmsGateway();
            if ($request->id == 0) {
                //判斷無ID時, code是否已存在
                if ($model->where('code', $request->code)->select('code')->first()) {
                    return response()->json([
                        'code' => 9999,
                        'message' => __('error_message.9999')
                    ]);
                } else {
                    $code = $request->code;
                }
            } else {
                $row = $model->where('id', $request->id)->select('code')->first();
                $code = $row->code;
            }

            $filter = array();
            $filter['code'] = $code;

            $data = array();
            $data['name'] = $request->name;
            $data['url'] = $request->url;
            $data['memo'] = $request->memo;
            $data['status'] = $request->status;
            $data['info'] = $request->info;
            $data['admin'] = $request->ID;

            $result = $model->updateOrCreate($filter, $data);

            SmsGatewayCountry::where('sms_gateway_id', $result->id)
                ->update(['status' => 0]);
            if (count($request->country_id) > 0) {
                foreach($request->country_id as $country_id) {
                    $filter = array();
                    $filter['sms_gateway_id'] = $result->id;
                    $filter['country_id'] = $country_id;

                    $data = array();
                    $data['status'] = 1;
                    $data['admin'] = $request->ID;
                    SmsGatewayCountry::updateOrCreate($filter, $data);
                }
            }

            // 刪除Redis中相關的資料
            $redis = new REDIS();
            $redis->delete(array('country', 'list'));

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $result
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
     * sms/gateway/status
     * - 修改簡訊商狀態
     *
     * @bodyParam id integer 簡訊商ID Example: 1
     * @bodyParam status integer 狀態 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function modifyGatewayStatus(Request $request)
    {
        try {
            $model = new SmsGateway();

            $data = $model->find($request->id);
            if (is_object($data)) {
                $update = $data->update([
                    'status' => ($request->status == "true") ? 1 : 0
                ]);

                if ($update) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array('country', 'list'));

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
                    'code' => 4044,
                    'message' => __('error_message.4044')
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
     * sms/info/list
     * - 簡訊商戶資訊列表
     *
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "agent_group_name": "簡訊群組名稱,ex:預設簡訊群組",
     *            "sms_gateway_name": "簡訊稱,ex: sms-get",
     *            "info": "簡訊商戶資訊(json), ex: {'abc':'123'}",
     *            "sort": "排序; ex: 10",
     *            "status": "狀態; ex: 1",
     *            "sms_gateway_id": "簡訊商ID;  ex:1",
     *            "sms_gateway_info": "簡訊商參數資料; ex: {'abc':'123'}",
     *            "countries": "國家相關資訊; ex: tw:台灣:886,us:美國:1",
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
    public function getGatewayInfoList(Request $request)
    {
        try {
            $sub = DB::table('agents as a')
                ->whereRaw("a.hierarchy like '%," . $request->HOST . ",%'");
            if ($request->agent_username != '') {
                $sub = $sub->whereRaw("a.username = '" . $request->agent_username . "'");
            }
            $sub = $sub->groupBy('a.sms_gateway_group_id')
                ->select('a.sms_gateway_group_id');

            $model = DB::table('sms_gateway_infos as sgi')
                ->join('sms_gateways as sg', 'sg.id', 'sgi.sms_gateway_id')
                ->join(DB::raw('(' . $sub->toSql() . ') as a'), 'a.sms_gateway_group_id', 'sgi.sms_gateway_group_id')
                ->leftJoin('sms_gateway_info_countries as sgic', function ($join) {
                    $join->on('sgic.sms_gateway_info_id', 'sgi.id')
                        ->where('sgic.status', 1);
                })
                ->leftJoin('countries as c', function ($join) {
                    $join->on('c.id', 'sgic.country_id')
                        ->where('c.status', 1);
                })
                ->leftJoin('country_names as cn',function ($join) use ($request) {
                    $join->on('cn.country_id', 'c.id')
                        ->where('cn.language_id', $request->LANGUAGE);
                })
                ->join('agent_groups as ag', function ($join) {
                    $join->on('ag.id', 'a.sms_gateway_group_id')
                        ->whereRaw('ag.agent_group_type = 3');
                });

            $total = $model->count();

            $data = $model->groupBy('sgi.id')
                ->orderBy('sgi.status', 'DESC')
                ->orderBy('sgi.sort', 'ASC')
                ->select(
                    'sgi.id',
                    'ag.name as agent_group_name',
                    'sg.name as sms_gateway_name',
                    'example.invalid as info',
                    'sgi.sort',
                    'sgi.status',
                    'sg.id as sms_gateway_id',
                    'example.invalid as sms_gateway_info',
                    DB::raw("GROUP_CONCAT(CONCAT(LOWER(c.code), ':', IFNULL(cn.name, c.name), ':', c.country_code)) as countries")
                )
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page);
            $sql = $data->toSql();
            $binds = $data->getBindings();
            $data = $data->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page),
                'sql' => $sql,
                'binds' => $binds
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
     * sms/info/show
     * - 簡訊商戶資訊
     *
     * @bodyParam id integer required 簡訊商戶資訊ID Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "gateway_id": "簡訊商ID,ex:1",
     *            "gateway_name": "簡訊商名稱,ex: sms-get",
     *            "gateway_info": "簡訊商參數(json), ex: {'abc':'123'}",
     *            "info": "簡訊商戶資訊(json), ex: {'abc':'123'}",
     *            "sort": "排序; ex: 10",
     *            "status": "狀態; ex: 1",
     *            "country_id": "可用國家ID; ex: 1,2,3,4"
     *        }
     *    ]
     * }
     */
    public function getGatewayInfo(Request $request)
    {
        try {
            $model = DB::table('sms_gateway_infos as sgi')
                ->join('sms_gateways as sg', 'sg.id', 'sgi.sms_gateway_id')
                ->leftJoin('sms_gateway_info_countries as sgic', function ($join) {
                    $join->on('sgic.sms_gateway_info_id', 'sgi.id')
                        ->where('sgic.status', 1);
                })
                ->leftJoin('countries as c', function ($join) {
                    $join->on('c.id', 'sgic.country_id')
                        ->where('c.status', 1);
                })
                ->leftJoin('country_names as cn', function ($join) use ($request) {
                    $join->on('cn.country_id', 'c.id')
                        ->where('cn.language_id', $request->LANGUAGE);
                })
                ->where('sgi.id', $request->id)
                ->groupBy('sgi.id')
                ->select(
                    'sgi.id',
                    'sg.id as gateway_id',
                    'sg.name as gateway_name',
                    'example.invalid as gateway_info',
                    'example.invalid',
                    'sgi.sort',
                    'sgi.status',
                    DB::raw('GROUP_CONCAT(c.id) as country_id')
                );
            $data = $model->first();
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
     * sms/info/save
     * - 新增/修改簡訊商資訊
     *
     * @bodyParam id integer 簡訊商資訊ID Ex: 1
     * @bodyParam gateway_id integer 簡訊商ID Ex: 1
     * @bodyParam status integer 狀態; Ex: 1
     * @bodyParam sort integer 排序 Ex: 0
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function modifyGatewayInfo(Request $request)
    {
        try {
            $model = new SmsGatewayInfo();
            $data = $model->find($request->id);
            if (is_object($data)) {
                $sms_gateway = SmsGateway::where('id', $request->gateway_id)
                    ->select('id', 'info')
                    ->first();
                $params = json_decode($sms_gateway->info);
                $info = array();
                foreach($params as $key => $param) {
                    $info[$key] = $request->$key;
                }

                $ar = array();
                if (isset($request->status)) {
                    $ar['status'] = $request->status;
                }
                if (isset($request->sort)) {
                    $ar['sort'] = $request->sort;
                }
                if (isset($info)) {
                    $ar['info'] = json_encode($info);
                }
                $update = $data->update($ar);

                if ($update) {
                    SmsGatewayInfoCountry::where('sms_gateway_info_id', $request->id)
                        ->update(['status' => 0]);
                    if (count($request->country_id) > 0) {
                        foreach($request->country_id as $country_id) {
                            $filter = array();
                            $filter['sms_gateway_info_id'] = $request->id;
                            $filter['country_id'] = $country_id;

                            $data = array();
                            $data['status'] = 1;
                            $data['admin'] = $request->ID;
                            SmsGatewayInfoCountry::updateOrCreate($filter, $data);
                        }
                    }

                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array('country', 'list'));
                    
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
                    'code' => 4044,
                    'message' => __('error_message.4044')
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
     * sms/info/status
     * - 修改簡訊商戶資訊狀態
     *
     * @bodyParam id integer 簡訊商戶資訊ID Example: 1
     * @bodyParam status integer 狀態 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function modifyGatewayInfoStatus(Request $request)
    {
        try {
            $model = new SmsGatewayInfo();

            $data = $model->find($request->id);
            if (is_object($data)) {
                $update = $data->update([
                    'status' => ($request->status == "true") ? 1 : 0
                ]);

                if ($update) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array('country', 'list'));

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
                    'code' => 4044,
                    'message' => __('error_message.4044')
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
     * sms/record
     * - 簡訊發送紀錄
     *
     * @bodyParam member_username string 玩家名稱 Example: member001
     * @bodyParam agent_username string 代理名稱 Example: agent001
     * @bodyParam mobile string 手機號碼 Example: 886123456789
     * @bodyParam sms_gateway_id integer 簡訊商ID Example: 1
     * @bodyParam start_time datetiem 開始時間 Example: 2020-01-01 00:00
     * @bodyParam end_time datetiem 結束時間 Example: 2020-01-02 00:00
     * @bodyParam status integer 狀態 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "sms_gateway_name": "簡訊商名稱,ex:sms-get",
     *            "agent_username": "代理名稱,ex:Agent001",
     *            "username": "玩家名稱,ex:Member001",
     *            "mobile": "發送號碼",
     *            "message_content": "簡訊內容",
     *            "transaction_id": "三方transaction id",
     *            "response_message": "回傳訊息",
     *            "status": "狀態; 0:未發送 1:已發送 2:發送完成 ex: 1",
     *            "created_at": "發送時間; ex: 2020-01-02 03:04:05"
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
    public function record (Request $request)
    {
        try {
            $model = DB::table('sms_send_records as ssr')
                ->join('members as m', function ($join) use ($request) {
                    $join->on('m.id', 'ssr.member_id');
                    if ($request->member_username != '') {
                        $join->where('m.username', $request->member_username);
                    }
                })
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                    if ($request->agent_username != '') {
                        $join->where('a.username', $request->agent_username);
                    }
                })
                ->join('sms_gateways as sg', function ($join) use ($request) {
                    $join->on('sg.id', 'ssr.sms_gateway_id');
                    if ($request->sms_gateway_id) {
                        $join->where('sg.id', $request->sms_gateway_id);
                    }
                })
                ->where('ssr.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')))
                ->where('ssr.created_at', '<', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour')));
            if ($request->status != '') {
                $model = $model->where('ssr.status', $request->status);
            }

            if ($request->mobile != '') {
                $model = $model->where('ssr.mobile', $request->mobile);
            }

            $total = $model->count();

            $data = $model->orderBy('ssr.created_at', 'DESC')
                ->select(
                    'sg.name as sms_gateway_name',
                    'a.username as agent_username',
                    'm.username',
                    'ssr.mobile',
                    'ssr.message_content',
                    'ssr.transaction_id',
                    'ssr.response_message',
                    'ssr.status',
                    DB::raw('DATE_ADD(ssr.created_at, INTERVAL 8 hour) AS created_at')
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
     * agent/sms/agent_group_setting
     * - 簡訊群組設定
     *
     * @bodyParam member_username string 玩家名稱 Example: member001
     * @bodyParam agent_username string 代理名稱 Example: agent001
     * @bodyParam mobile string 手機號碼 Example: 886123456789
     * @bodyParam sms_gateway_id integer 簡訊商ID Example: 1
     * @bodyParam start_time datetiem 開始時間 Example: 2020-01-01 00:00
     * @bodyParam end_time datetiem 結束時間 Example: 2020-01-02 00:00
     * @bodyParam status integer 狀態 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "sms_gateway_name": "簡訊商名稱,ex:sms-get",
     *            "agent_username": "代理名稱,ex:Agent001",
     *            "username": "玩家名稱,ex:Member001",
     *            "mobile": "發送號碼",
     *            "message_content": "簡訊內容",
     *            "transaction_id": "三方transaction id",
     *            "response_message": "回傳訊息",
     *            "status": "狀態; 0:未發送 1:已發送 2:發送完成 ex: 1",
     *            "created_at": "發送時間; ex: 2020-01-02 03:04:05"
     *        }
     *    ]
     * }
     */
    public function agentGroupSetting(Request $request)
    {
        try {
            $data = AgentGroup::where('id', $request->group_id)
                ->where('agent_group_type', 3)
                ->select(
                    'id as group_id',
                    'name as group_name',
                    'memo as group_memo'
                )
                ->first();
            if (!is_object($data)) {
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044'),
                    'data' => $data
                ]);
            }

            //確認是否有開啟這個組別的權限
            $checkAgentGroupPermission = $this->checkAgentGroupPermission($request->group_id, $request->ID);
            //Admin跟Admin的子帳號不檢查
            if($request->HOST != 1 && !$checkAgentGroupPermission) {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003'),
                    'data' => ""
                ]);
            }

            //只取一筆; 如果未設定, 就取預設簡訊的setting
            $group_detail = DB::table('agent_groups as ag')
                ->leftJoin('sms_gateway_group_details as sggd', 'sggd.agent_group_id', 'ag.id')
                ->where('ag.agent_group_type', 3)
                ->where(function ($query) use ($request) {
                    $query->where('ag.is_default', 1)
                        ->orWhere('sggd.agent_group_id', $request->group_id);
                })
                ->orderBy('is_default', 'ASC')
                ->select(
                    DB::raw('CASE WHEN sggd.agent_group_id = ' . $request->group_id . ' THEN sggd.id ELSE 0 END id'),
                    'sggd.setting'
                )
                ->first();
            $data['group_detail'] = $group_detail;

            $sms_text = DB::table('countries as c')
                ->leftJoin('country_names as cn', function ($join) use ($request) {
                    $join->on('cn.country_id', 'cn.id')
                        ->where('cn.language_id', $request->LANGUAGE);
                })
                ->leftJoin('params as p', function ($join) {
                    $join->on('p.status', 'c.status')
                        ->whereRaw("p.type = 'sms_text_type'")
                        ->where('p.status', 1);
                })
                ->leftJoin('sms_texts as st', function ($join) use ($request) {
                    $join->on('st.country_id', 'c.id')
                        ->whereRaw('st.type_id = p.value')
                        ->where('st.sms_gateway_group_id', $request->group_id);
                })
                ->where('c.status', 1)
                ->orderBy('p.sort', 'ASC')
                ->orderBy('c.id', 'ASC')
                ->select(
                    'st.id',
                    DB::raw('IFNULL(cn.name, c.name) as country_name'),
                    'c.id as country_id',
                    'p.remark',
                    DB::raw('IFNULL(st.type_id, p.value) as type_id'),
                    'st.text',
                    'st.status',
                    'p.parameter'
                )
                ->get();

            $data['sms_text'] = $sms_text;

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

    public function saveAgentGroupSetting(Request $request)
    {
        try {
            $model = SmsGatewayGroupDetail::where('agent_group_id', $request->group_id);

            if (!is_object($model)) {
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044')
                ]);
            }
            $model->update([
                'setting' => $request->setting
            ]);
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000')
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

    public function saveSmsTextContent(Request $request)
    {
        try {
            if (count($request->sms_text) > 0) {
                foreach($request->sms_text as $type_id => $obj) {
                    foreach($obj as $country_id => $text) {
                        $filter = array();
                        $filter['sms_gateway_group_id'] = $request->group_id;
                        $filter['type_id'] = $type_id;
                        $filter['country_id'] = $country_id;

                        $data = array();
                        $data['agent_id'] = 1;
                        $data['text'] = $text;
                        $data['status'] = 1;
                        $data['admin'] = 0;
                        SmsText::updateOrCreate($filter, $data);
                    }
                }
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $request->all()
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

    private function checkAgentGroupPermission($agent_group_id, $agent_id)
    {
        $result = false;
        $agent_group_permissions = DB::table('agent_group_permissions')
            ->where('agent_id', $agent_id)
            ->where('agent_group_id', $agent_group_id)
            ->first();
        if(is_object($agent_group_permissions)) {
            $result = true;
        }

        return $result;
    }

    private function messages()
    {
        return [

            'bank_code has exist' => __('error_message.2001')
        ];
    }
}