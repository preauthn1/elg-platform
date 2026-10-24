<?php

namespace App\Http\Controllers;

use App\Models\AgentPersonalSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Enums\AgentCustomizedSettingKeyEnum;
use App\Enums\AgentPersonalSettingEnum;
use App\Models\Agent;
use App\Models\SummaryBetRecordAgent;
use App\Models\SummaryPointBarrel;
use App\Models\SummaryPointBarrelWinlose;
use App\Helpers\Common;
use App\REDIS;
use Validator;
use DB;

class PointBarrelController extends Controller
{
    /**
     * agent/list
     * -營運商列表(只能查看自己的下線，如果在我們下一層的代理，可以查詢他下面所有的代理)
     *
     * @bodyParam previous_agent_username integer 上級營運商名稱 Example: vworldmy
     * @bodyParam agent_username integer 營運商名稱 Example: vworldmy
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "營運商系統編號,ex:1",
     *            "host_id": "主帳號系統編號,ex:1",
     *            "parent": "上層代理的系統編號(0的話是第一層),ex:0",
     *            "parent_username": "上層代理的代理帳號(0的話是第一層),ex:0",
     *            "code": "推薦碼,ex:123456",
     *            "username": "營運商帳號,ex:dmin",
     *            "balance": "餘額,ex:100",
     *            "email": "營運商email,ex:user@example.invalid",
     *            "nickname": "暱稱,ex:xxx",
     *            "mobile": "手機號碼,ex:0912345678",
     *            "percentage": "分成百分比,ex:5",
     *            "timezone_id": "時區系統編號,ex:1",
     *            "country_id": "國家系統編號,ex:1",
     *            "zip_code": "區碼,ex:230",
     *            "district": "地區,ex:中正區",
     *            "city": "城市,ex:台北市",
     *            "address": "地址,ex:忠孝西路",
     *            "email_validate": "是否需要電子信箱認證0:不必需1:必需,ex:1",
     *            "mobile_validate": "是否需要手機認證0:不必需1:必需,ex:1",
     *            "frontend_register":"前端是否開放註冊0:不開放1:開放,ex:1",
     *            "has_chat":"是否開放聊天室, 0:不開放1:開放,ex:1",
     *            "has_pc":"是否有PC版，0:沒有1:有,ex:1",
     *            "has_mobile":"是否有手機版，0:沒有1:有,ex:1",
     *            "has_online_payment":"是否有線上儲值，0:沒有1:有,ex:1",
     *            "has_app":"是否有APP下載，0:沒有1:有,ex:1",
     *            "transaction_all":"進入遊戲時是否將所有錢轉入，0:否1:是,ex:1",
     *            "agent_backend_transaction":"是否可以在代理後台開洗分，0:否1:是,ex:1",
     *            "status": "狀態(0:凍結,1:正常),ex:1",
     *            "last_login": "最後登入時間,ex:2019-04-09 01:28:23",
     *            "created_at": "建立時間,ex:2019-04-09 01:28:23"
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
    public function get_agent(Request $request)
    {
        try {
            //上層代理
            $superior = Agent::where('id', $request->PARENT)->first();

			//找出本身分桶分成比例
            if ($request->HOST == 1) {
                if ($request->previous_agent_username != '') {
                    $agent = Agent::where('username', $request->previous_agent_username)
                        ->where('host_id', 0)
                        ->first();
                    if (!is_object($agent)) {
                        return response()->json([
                            'code' => 1005,
                            'message' => __('error_message.1005')
                        ]);
                    }
                }
            } else {
                $agent = Agent::where('id', $request->HOST)->first();
            }
            $agent_id = $agent->id;

            $group_share = AgentPersonalSettings::where('setting_id', AgentPersonalSettingEnum::POINT_BARREL_SHARE)
                ->where('agent_id', explode('/', $agent->hierarchy_path)[2])
                ->first();

            $pb = 130;
            if ($agent_id > 1) {
                $parent = DB::table('agents as a')
                    ->join('agent_groups as ag', function ($join) {
                        $join->on('ag.id', 'a.customized_setting_group_id')
                            ->where('ag.agent_group_type', 1);
                    })
                    ->join('agent_customized_settings as acs', function ($join) {
                        $join->on('acs.agent_group_id', 'ag.id')
                            ->where('acs.setting_key', AgentCustomizedSettingKeyEnum::POINT_BARREL_SETTING)
                            ->where('acs.value', 1);
                    })
                    ->join('agent_personal_settings as aps', function ($join) {
                        $join->on('aps.agent_id', 'a.id')
                            ->where('aps.setting_id', AgentPersonalSettingEnum::POINT_BARREL_SHARE);
                    })
                    ->where('a.id', $agent_id)
                    ->where('a.host_id', 0)
                    ->select(
                        'a.id as agent_id',
                        'aps.value as point_share'
                    )
                    ->first();
                if (!is_object($parent)) {
                    return response()->json([
                        'code' => 1801,
                        'message' => __('error_message.1801')
                    ]);
                }
                $pb = $parent->point_share;
            }

			//找出下層分桶分成比例
			$model = DB::table('agents as a')
				->join('agent_groups as ag', function ($join) {
					$join->on('ag.id', 'a.customized_setting_group_id')
						->where('ag.agent_group_type', 1);
				})
				->join('agent_customized_settings as acs', function ($join) {
					$join->on('acs.agent_group_id', 'ag.id')
						->where('acs.setting_key', AgentCustomizedSettingKeyEnum::POINT_BARREL_SETTING)
						->where('acs.value', 1);
				})
				->leftJoin('agent_personal_settings as aps', function ($join) {
					$join->on('aps.agent_id', 'a.id')
						->where('aps.setting_id', AgentPersonalSettingEnum::POINT_BARREL_SHARE);
				})
				->where('a.parent', $agent_id)
				->where('a.host_id', 0)
                ->whereNotIn('a.username', ['unlimit']);

            if ($request->agent_username != '') {
                $model = $model->where('a.username', $request->agent_username);
            }

            $total = $model->count();

            $data = $model->orderBy('sort', 'DESC')
                ->select(
                    'a.id as agent_id',
					'a.username as username',
                    'aps.value as point_share',
                    DB::raw('IFNULL(aps.value, 999) as sort')
				)
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'max' => $pb,
                'min' => ((($pb - 30) < 0) ? 0 : ($pb - 30)),
                'my_superior' => $superior['username'],
                'my_shares' => $pb . '/' . $group_share['value'],
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
     * point_barrel/point_share
     * - 持股份設定
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam agent_id integer required 代理id Example: 1
     * @bodyParam share integer 持股份設定 Example: 100
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function set_point_share(Request $request)
    {
        try {
            $model = new AgentPersonalSettings();
            /*
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            */

            $filter = array();
            $filter['setting_id'] = AgentPersonalSettingEnum::POINT_BARREL_SHARE;
            $filter['agent_id'] = $request->agent_id;

            $data = array();
            $data['value'] = $request->share;
            $data['created_by'] = $request->ID;
            $result = $model->updateOrCreate($filter, $data);

            //檢查下級代理持股份並修改
            $result = DB::update(
                'call SP_reset_point_barrel_share_by_agent(?)',
                array($request->agent_id)
            );

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

    /**
     * point_barrel/performance
     * - 持股份設定
     *
     * @bodyParam start_date date required 开始日期 Example: '2021-01-01'
     * @bodyParam end_date date required 结束日期 Example: '2021-01-01'
     * @bodyParam agent_username string 代理商名称 Example: agent
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function performance(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'start_time' => 'required|date_format:Y-m-d H:i:s',
                'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-1day'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-1day'));

            if ($request->agent_username != '') {
                $agent = Agent::where('hierarchy', 'like', '%,' . $request->HOST . ',%')
                    ->where('username', $request->agent_username)
                    ->first();
            } elseif ($request->HOST == 1) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'summary' => []
                ]);
            } else {
                $agent = Agent::where('id', $request->HOST)->first();
            };

            if (!is_object($agent)) {
                return response()->json([
                    'code' => 1005,
                    'message' => __('error_message.1005')
                ]);
            }


            $agents = explode('/', $agent->hierarchy_path);
            $platform_provider_id = 0;
            if (is_array($agents)) {
                $platform_provider_id = $agents[2];
            }

            //取出PB
            $pb = AgentPersonalSettings::where('agent_id', $platform_provider_id)
                ->where('setting_id', AgentPersonalSettingEnum::POINT_BARREL_SHARE)
                ->first();

            $summary = DB::table('summary_point_barrel_winloses as spbw')
                ->leftJoin('vendors as v', 'v.id', 'spbw.vendor_id')
                ->whereBetween('spbw.summary_time', [$start_time, $end_time])
                ->where('spbw.agent_id', $platform_provider_id)
                ->groupBy('spbw.vendor_id')
                ->orderBy('spbw.vendor_id', 'ASC')
                ->select(
                    'spbw.id',
                    'spbw.vendor_id',
                    DB::raw("CASE WHEN spbw.vendor_id = 0 THEN 'Settlement' ELSE v.name END AS vendor_username"),
                    DB::raw('SUM(spbw.turnover) as turnover'),
                    DB::raw('SUM(spbw.game_spending) * -1 as game_spending'),
                    DB::raw('SUM(spbw.game_profit) as game_profit'),
                    'spbw.memo'
                )
                ->get();

            $data = DB::table('summary_point_barrels as spb')
                ->join('agents as a', function ($join) use ($request, $agent) {
                    $join->on('a.id', 'spb.agent_id')
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                })
                ->leftJoin('vendors as v', 'v.id', 'spb.vendor_id')
                ->whereBetween('summary_time', [$start_time, $end_time])
                ->where(function ($query) use ($agent) {
                    $query->where('agent_id', $agent->id)
                        ->orWhere('parent_id', $agent->id);
                })
                ->groupBy('spb.vendor_id')
                ->groupBy('spb.hierarchy_path')
                ->orderBy('spb.vendor_id', 'ASC')
                ->orderBy('main', 'DESC')
                ->select(
                    'spb.agent_id',
                    'a.username as agent_username',
                    'spb.parent_id',
                    'spb.vendor_id',
                    DB::raw("CASE WHEN spb.vendor_id = 0 THEN 'Settlement' ELSE v.name END AS vendor_username"),
                    DB::raw('SUM(total_turnover) as total_turnover'),
                    DB::raw('SUM(total_nett_profit) as total_nett_profit'),
                    DB::raw('SUM(self_turnover) as self_turnover'),
                    DB::raw('SUM(self_nett_profit) as self_nett_profit'),
                    DB::raw('share_point as share_point'),
                    DB::raw("CASE WHEN spb.agent_id = '" . $agent->id . "' THEN 1 ELSE 0 END as main")
                )
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'summary' => $summary,
                'data' => $data,
                'pb' => $pb->value
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

    public function cost(Request $request)
    {
        try {
            $model = DB::table('summary_point_barrel_winloses as spbw')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'spbw.agent_id')
                        ->where('a.id', $request->HOST);
                })
                ->where('spbw.vendor_id', 0);
            $total = $model->count();

            $data = $model->orderBy('spbw.summary_time', 'DESC')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    'spbw.id',
                    DB::raw("DATE(DATE_ADD(spbw.summary_time, INTERVAL 8 HOUR)) AS summary_time"),
                    'a.username as agent_username',
                    'a.id as agent_id',
                    'spbw.game_profit',
                    'spbw.memo',
                    DB::raw('CASE WHEN spbw.created_at = spbw.updated_at THEN 0 ELSE 1 END AS edit'),
                    DB::raw('CASE WHEN EXISTS (SELECT * FROM summary_point_barrels WHERE summary_time = spbw.summary_time) THEN 1 ELSE 0 END as settle')
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

    /**
     * point_barrel/set_cost
     * - 遊戲盈利設定
     *
     * @bodyParam id int required SummaryPointBarrelWinloseID Example: 1
     * @bodyParam cost integer required 遊戲盈利 Example: -6000
     * @bodyParam memo string 備註 Example: test
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function set_cost(Request $request)
    {
        try {
            $request->date = isset($request->date)
                ? date($request->date, strtotime('16hour'))
                : date('Y-m-d 16:00:00');

            $data = array();
            $data['game_profit'] = $request->cost;
            $data['memo'] = $request->memo;

            $result = SummaryPointBarrelWinlose::where('id', $request->id)
                ->where('vendor_id', 0)
                ->update($data);
            if ($result) {
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

    public function settle(Request $request)
    {
        try {
            $result = DB::insert('CALL SP_calc_point_barrels(?, ?)',
                array(
                    $request->summary_time,
                    $request->agent_id
                ));
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

    private function messages()
    {
        return [
            'id.required' => __('error_message.2017'),
            'id.digits_between' => __('error_message.2018'),

            'username.required' => __('error_message.1026'),
            'username.alpha_num' => __('error_message.1025'),
            'username.between' => __('error_message.1024'),
            'username.unique' => __('error_message.1002'),

            'nickname.between' => __('error_message.2024'),

            'email.required' => __('error_message.1013'),
            'email.email' => __('error_message.1014'),
            'email.between' => __('error_message.1015'),

            'password.required' => __('error_message.1016'),
            'password.between' => __('error_message.1017'),
            'password.confirmed' => __('error_message.2025'),

            'new_password.required' => __('error_message.1019'),
            'new_password.between' => __('error_message.1020'),
            'new_password.confirmed' => __('error_message.1021'),

            'confirm_password.different' => __('error_message.1021'),

            'mobile.digits_between' => __('error_message.1036'),

            'timezone_id.digits_between' => __('error_message.1054'),

            'country_id.digits_between' => __('error_message.2022'),

            'zip_code.digits_between' => __('error_message.1038'),

            'district.between' => __('error_message.1039'),

            'city.between' => __('error_message.1040'),

            'address.between' => __('error_message.1041'),

            'email_validate.boolean' => __('error_message.1091'),
            'mobile_validate.boolean' => __('error_message.1092'),
            'frontend_register.boolean' => __('error_message.1065'),

            'status.boolean' => __('error_message.2009'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}