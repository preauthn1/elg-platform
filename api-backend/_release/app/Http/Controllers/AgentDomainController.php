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
     * -域名列表
     *
     * @bodyParam agent_id integer 營運商名稱 Example: 1
     * @bodyParam domain string 域名 Example: agent1
     * @bodyParam template_id integer 模版名稱 Example: 2
     * @bodyParam status boolean  狀態 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "系統編號,ex:1",
     *            "agent_id": "營運商系統編號,ex:1",
     *            "domain": "域名,ex:agent1",
     *            "template_id": "模版系統編號,ex:1",
     *            "status": "狀態,ex:1",
     *            "created_at": "建立時間,ex:2019-01-01 00:00:00",
     *            "updated_at": "更新時間,ex:2019-12-31 23:59:59",
     *            "admin": 1
     *          },
     *          {
     *            "id": "系統編號,ex:2",
     *            "agent_id": "營運商系統編號,ex:1",
     *            "domain": "域名,ex:agent2",
     *            "template_id": "模版系統編號,ex:2",
     *            "status": "狀態,ex:0",
     *            "created_at": "建立時間,ex:2019-01-01 00:00:00",
     *            "updated_at": "更新時間,ex:2019-12-31 23:59:59",
     *            "admin": 1
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
     *
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new AgentDomain();
            // $sort = $request->sort ? $request->sort : 'updated_at';
            // 驗證排序欄位是存在的
            // if (! in_array($sort, $model->getFillable())) {
            // return response()->json([
            // 'code' => 9002,
            // 'message' => __('error_message.9002')
            // ]);
            // }
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
                'agent_domain',
                'list',
                $request->agent_id,
                $request->domain,
                $request->template_id,
                $request->status,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'agent_domain',
                'list',
                $request->agent_id,
                $request->domain,
                $request->template_id,
                $request->status,
                'total'
            );
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                // Redis不存在就重新查詢
                if (isset($request->agent_id)) {
                    $model = $model->where('agent_id', $request->agent_id);
                }
                if (isset($request->domain)) {
                    $model = $model->where('domain', 'like', '%' . $request->domain . '%');
                }
                if (isset($request->template_id)) {
                    $model = $model->where('template_id', $request->template_id);
                }
                if (isset($request->status)) {
                    $model = $model->where('status', $request->status);
                } else {
                    $model = $model->whereIn('status', [
                        0,
                        1
                    ]);
                }
                $total = $model->count();
                $data = $model->orderBy('updated_at', 'desc')
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
     * agentDomain/add
     * -新增域名
     *
     * @bodyParam agent_id integer required 營運商系統編號 Example: 1
     * @bodyParam domain string required 域名 Example: agent1
     * @bodyParam status boolean 狀態:0停用,1正常(預設為0) Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": "系統編號,ex:1"
     * }
     */
    public function create(Request $request)
    {
        try {
            $data = array();
            $model = new AgentDomain();
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $data['agent_id'] = $request->agent_id;
            $data['domain'] = $request->domain;
            $data['template_id'] = 0;
            $data['status'] = isset($request->status) ? $request->status : 0;
            $data['admin'] = $request->ID;
            if ($result = $model->create($data)) {
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'agent_domain',
                    'list'
                ));
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $result->id
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

    /**
     * agentDomain/edit
     * -編輯域名
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam agent_id integer 營運商系統編號 Example: 1
     * @bodyParam domain string 域名 Example: agent1
     * @bodyParam status boolean 狀態:0停用,1正常 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new AgentDomain();
            $rules = $model->rules_update;
            $rules['domain'] = 'domain|unique:agent_domains,domain,' . $request->id;
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            if ($row = $model->find($request->id)) {
                $data = array();
                $data['agent_id'] = isset($request->agent_id) ? $request->agent_id : $row->agent_id;
                $data['domain'] = isset($request->domain) ? $request->domain : $row->domain;
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'agent_domain',
                        'list'
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
     * agentDomain/delete
     * -停用域名
     *
     * @bodyParam id string required 系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function delete(Request $request)
    {
        try {
            $model = new AgentDomain();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_delete, $this->messages());
            if (! $validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 先確認記錄是否存在
            if ($model->find($request->id)) {
                // 更新記錄狀態
                if ($model->find($request->id)->update(array(
                    'status' => 2,
                    'admin' => $request->ID
                ))) {
                    $key = array(
                        'agent_domain',
                        'list'
                    );
                    $redis = new REDIS();
                    $redis->delete($key);
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

            'agent_id.required' => __('error_message.2017'),
            'agent_id.digits_between' => __('error_message.2018'),

            'domain.required' => __('error_message.1209'),
            'domain.domain' => __('error_message.1210'),
            'domain.unique' => __('error_message.1211'),

            'template_id.digits_between' => __('error_message.1207'),

            'status.boolean' => __('error_message.1208'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}
