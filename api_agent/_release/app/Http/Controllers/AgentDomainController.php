<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AgentDomain;
use App\Models\Agent;
use App\Repositories\AgentRepository;
use App\REDIS;
use Validator;
use DB;

class AgentDomainController extends Controller
{
    /**
     * agentDomain/list
     * -取得可用域名(只能查詢自己的及自己下線的)
     *
     * @bodyParam agent_id integer 類型名稱 Example: 1
     * @bodyParam domain string 類型名稱 Example: agent1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "domain": "域名,ex:agent1",
     *            "template_id": "模版系統編號,ex:1",
     *            "logo_pc": "電腦版Logo,ex:http://xxxxx.xxx.xxx/xxx.jpg",
     *            "logo_mobile": "手機版Logo,ex:http://xxxxx.xxx.xxx/xxx.jpg",
     *            "icon": "favorite icon,ex:http://xxxxx.xxx.xxx/xxx.jpg",
     *            "title": "網站名稱,ex:Hello",
     *            "status":"狀態0:停用1:啟用,ex:1"
     *          },
     *          {
     *            "id": 2,
     *            "domain": "agent2",
     *            "template_id": "2",
     *            "logo_pc": "http://xxxxx.xxx.xxx/xxx.jpg",
     *            "logo_mobile": "http://xxxxx.xxx.xxx/xxx.jpg",
     *            "title": "Hello",
     *            "status":1
     *          }
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
            $model = new AgentDomain();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            if ($this->isChild($request->HOST, $request->agent_id) || $request->HOST == $request->agent_id || $request->agent_id == '') {
                // Redis名稱
                $key = array(
                    'agent_domain_v4',
                    'list',
                    $request->HOST,
                    $request->agent_id,
                    $request->domain,
                    $request->page,
                    $request->per_page
                );
                $total_key = array(
                    'agent_domain_v4',
                    'list',
                    $request->HOST,
                    $request->agent_id,
                    $request->domain,
                    'total'
                );
                $redis = new REDIS();

                if (!$redis->exists($key)) {


                    $rep = new AgentRepository();
                    $isSuperAdmin = $rep->isSuperAdminOrSubAccount($request->HOST);

                    $model = DB::table("agent_domains as d");

                    if(!$isSuperAdmin)
                    {
                        if (isset($request->agent_id)) {
                            $model = $model->where('agent_id', $request->agent_id);
                        } else {
                            $model = $model->where('agent_id', $request->HOST);
                        }
                    }                  
                    
                    if ($request->domain != null) {
                        $model = $model->where('domain', 'like', '%' . $request->domain . '%');
                    }

                    $total = $model->count();

                    $data = $model
                        ->leftJoin("agents as a" , 'd.agent_id' , 'a.id')
                        ->leftJoin("organizations as o" , 'a.organization_id' , 'o.id')
                        ->orderBy('d.updated_at', 'desc')
                        ->orderBy('d.id', 'desc')
                        ->select(
                            'd.id',
                            'a.id as agent_id',
                            'a.username as username',
                            'a.organization_id as organization_id',
                            'o.name as organization_name',
                            'd.domain',
                            'd.template_id',
                            'd.logo_pc',
                            'd.logo_mobile',
                            'd.icon',
                            'd.title',
                            'd.status',
                            'd.updated_at',
                        )
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
            } else {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003')
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
     * agentDomain/view
     * -取得可用域名詳情(只會顯示自己的)
     *
     * @bodyParam id  integer required 域名編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *            "id": "系統編號,ex:1",
     *            "domain": "域名,ex:agent1",
     *            "template_id": "模版系統編號,ex:1",
     *            "logo_pc": "電腦版Logo,ex:http://xxxxx.xxx.xxx/xxx.jpg",
     *            "logo_mobile": "手機版Logo,ex:http://xxxxx.xxx.xxx/xxx.jpg",
     *            "icon": "favorite icon,ex:http://xxxxx.xxx.xxx/xxx.jpg",
     *            "title": "網站名稱,ex:Hello",
     *            "status": "狀態0:停用1:啟用,ex:1"
     *      }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new AgentDomain();
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $rep = new AgentRepository();
            $isSuperAdmin = $rep->isSuperAdminOrSubAccount($request->HOST);

            if(!$isSuperAdmin)
            {
                $model = $model->where('agent_id', $request->HOST);
            }

            $data = $model                
                ->where('id', $request->id)
                ->select(
                    'id',
                    'domain',
                    'template_id',
                    'logo_pc',
                    'logo_mobile',
                    'icon',
                    'add_home_image',
                    'title',
                    'status'
                )
                ->first();


            if(!is_object($data))
            {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003'),
                    'data' => $validator->errors()
                        ->all()
                ]);
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
     * agentDomain/setting
     * -設定域名要使用的模版
     *
     * @bodyParam id integer required 域名系統編號 Example: 1
     * @bodyParam template_id integer 模版系統編號 Example: 1
     * @bodyParam logo_pc string base64 file 電腦版Logo圖片
     * @bodyParam logo_mobile string base64 file 手機版Logo圖片
     * @bodyParam icon string base64 file favorite icon
     * @bodyParam title string 網站名稱 Example:Hello Money
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function setting(Request $request)
    {
        try {
            $model = new AgentDomain();
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            if ($request->id == '') {
                $model_data = array();
                $model_data['agent_id'] = $request->HOST;
                $model_data['domain'] = $request->domain;
                $model_data['template_id'] = $request->template_id;
                $model_data['title'] = $request->title;
                $model_data['status'] = $request->status;
                $model_data['admin'] = $request->ID;
                $result = $model->create($model_data);
                $request->id = $result->id;
            }

            if ($row = $model->join('agents as a', function ($join) use ($request) {
                $join->on('a.id', 'agent_domains.agent_id')
                    ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
            })
                ->where('agent_domains.id', $request->id)
                ->First()
            ) {
                $data = array();
                $data['template_id'] = isset($request->template_id) ? $request->template_id : $row->template_id;
                $data['title'] = isset($request->title) ? $request->title : $row->title;
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                if (isset($request->logo_pc) && $request->logo_pc != '') {
                    // issues_1045 改成選擇圖片時先上傳圖片到GCP
                    $data['logo_pc'] = $request->logo_pc;
                }
                if (isset($request->logo_mobile) && $request->logo_mobile != '') {
                    // issues_1045 改成選擇圖片時先上傳圖片到GCP
                    $data['logo_mobile'] = $request->logo_mobile;
                }
                if (isset($request->icon) && $request->icon != '') {
                    // issues_1045 改成選擇圖片時先上傳圖片到GCP
                    $data['icon'] = $request->icon;
                }
                if (isset($request->add_home_image) && $request->add_home_image != '') {
                    // issues_1045 改成選擇圖片時先上傳圖片到GCP
                    $data['add_home_image'] = $request->add_home_image;
                }
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'agent_domain'
                    ));
                    $redis->delete(array(
                        'domain'
                    ));
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
                    'code' => 1213,
                    'message' => __('error_message.1213')
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
     * agentDomain/self
     * - 取得自己當前域名
     *
     * @bodyParam $agent_id integer  代理ID Example: 1
     * @bodyParam $show_all integer  顯示全部 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function self(Request $request)
    {
        try {
            $agent_id = isset($request->agent_id) ? $request->agent_id : $request->ID;
            $show_all = isset($request->show_all) ? $request->show_all : 0;

            $agent = Agent::where('id', $agent_id)
                ->select('hierarchy')
                ->first();
            if (is_object($agent)) {
                foreach(explode(',', $agent->hierarchy) as $hierarchy) {
                    $domain = AgentDomain::where('agent_id', $hierarchy)
                        ->where('status', 1)
                        ->orderBy('sort', 'DESC');
                    $total = $domain->count();
                    if ($total > 0) {
                        if ($show_all == 1) {
                            $domain = $domain->get();
                        } else {
                            $domain = $domain->first();
                        }
                        $data = $domain;
                        break;
                    }
                }
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
            'id.required' => __('error_message.1216'),
            'id.digits_between' => __('error_message.1217'),

            'agent_id.digits_between' => __('error_message.2018'),

            'domain.required' => __('error_message.1209'),
            'domain.r_alpha_dash' => __('error_message.1210'),
            'domain.unique' => __('error_message.1211'),

            'template_id.required' => __('error_message.1206'),
            'template_id.digits_between' => __('error_message.1207'),

            'title.between' => __('error_message.1218'),

            'logo_pc.base64image' => __('error_message.2023'),

            'logo_mobile.base64image' => __('error_message.2023'),

            'status.boolean' => __('error_message.1208'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}