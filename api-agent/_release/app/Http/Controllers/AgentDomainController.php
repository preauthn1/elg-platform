<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\AgentDomain;
use App\REDIS;
use Validator;

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
            if ($this->isChild($request->ID, $request->agent_id) || $request->ID == $request->agent_id || $request->agent_id == '') {
                // Redis名稱
                $key = array(
                    'agent_domain',
                    'list',
                    $request->ID,
                    $request->agent_id,
                    $request->domain,
                    $request->page,
                    $request->per_page
                );
                $total_key = array(
                    'agent_domain',
                    'list',
                    $request->ID,
                    $request->agent_id,
                    $request->domain,
                    'total'
                );
                $redis = new REDIS();
                if (!$redis->exists($key)) {
                    // Redis不存在就重新查詢
                    if (isset($request->agent_id)) {
                        $model = $model->where('agent_id', $request->agent_id);
                    } else {
                        $model = $model->where('agent_id', $request->ID);
                    }
                    // $model = $model->where('status', 1);
                    if ($request->domain != null) {
                        $model = $model->where('domain', 'like', '%' . $request->domain . '%');
                    }
                    $total = $model->count();
                    $data = $model->select('id', 'domain', 'template_id', 'logo_pc', 'logo_mobile', 'icon', 'title', 'status')
                        ->orderBy('updated_at', 'desc')
                        ->orderBy('id', 'desc')
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
            // Redis名稱
            $key = array(
                'agent_domain',
                $request->id
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                // Redis不存在就重新查詢
                $data = $model->where('agent_id', $request->ID)
                    ->where('id', $request->id)
                    ->select('id', 'domain', 'template_id', 'logo_pc', 'logo_mobile', 'icon', 'title', 'status')
                    ->first();
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
            if ($row = $model->where('agent_id', $request->ID)
                ->where('id', $request->id)
                ->First()
            ) {
                $data = array();
                $data['template_id'] = isset($request->template_id) ? $request->template_id : $row->template_id;
                $data['title'] = isset($request->title) ? $request->title : $row->title;
                if (isset($request->logo_pc) && $request->logo_pc != '') {
                    // 移動圖檔至本地及上傳到GCP
                    $path = $this->uploadBase64File($request->logo_pc);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $data['logo_pc'] = $path['message'];
                }
                if (isset($request->logo_mobile) && $request->logo_mobile != '') {
                    // 移動圖檔至本地及上傳到GCP
                    $path = $this->uploadBase64File($request->logo_mobile);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $data['logo_mobile'] = $path['message'];
                }
                if (isset($request->icon) && $request->icon != '') {
                    // 移動圖檔至本地及上傳到GCP
                    $path = $this->uploadBase64File($request->icon);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $data['icon'] = $path['message'];
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