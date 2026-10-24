<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\AgentTemplate;
use App\REDIS;
use Validator;
use DB;

class AgentTemplateController extends Controller
{

    /**
     * agentTemplate/list
     * -營運商模版列表
     *
     * @bodyParam agent_id integer required 營運商編號 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "模版編號,ex:1",
     *            "template_name": "模版名稱,ex:pink",
     *            "template_thumbnail": "模版縮圖,ex:http://xxxx.xxx.xxx/xxx.jpg",
     *            "status": "啟用狀態(0:停用,1:啟用),ex:1",
     *            "created_at": "建立時間,ex:2019-01-01 00:00:00",
     *            "updated_at": "更新時間,ex:2019-12-31 23:59:59",
     *            "admin": 1
     *          },
     *          {
     *            "id": 2,
     *            "template_name": "black",
     *            "template_thumbnail": "http://xxxx.xxx.xxx/xxx.jpg",
     *            "status": 0,
     *            "created_at": "2019-01-01 00:00:00",
     *            "updated_at": "2019-12-31 23:59:59",
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
            $model = new AgentTemplate();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
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
                'agent_template',
                'list',
                $request->agent_id,
                $request->page,
                $request->per_page
            );
            $total_key = array(
                'agent_template',
                'list',
                $request->agent_id,
                'total'
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                // Redis不存在就重新查詢
                $data = DB::table('templates as t')->leftJoin('agent_templates as a', function ($join) use ($request) {
                    $join->on('t.id', '=', 'a.template_id')
                        ->where('a.agent_id', $request->agent_id)
                        ->where('a.status', 1);
                })
                    ->where('t.status', 1);
                $total = $data->count();

                $data = $data->select('t.id', 't.name as template_name', 't.thumbnail as template_thumbnail', 'a.status', 'a.created_at', 'a.updated_at', 'a.admin')
                    ->orderBy('t.id', 'desc')
                    ->orderBy('a.id', 'desc')
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
     * agentTemplate/setting
     * -設定營運商可用模版
     *
     * @bodyParam agent_id integer required 營運商編號 Example: 1
     * @bodyParam template_id integer required 模版編號 Example: 1
     * @bodyParam status boolean 狀態0:停用,1:啟用  Example:1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function setting(Request $request)
    {
        try {
            DB::beginTransaction();
            $model = new AgentTemplate();
            // 驗證資料是否正確
            $validator = Validator::make($request->all(), $model->rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 寫入新的
            $data = array();
            $data['agent_id'] = $request->agent_id;
            $data['template_id'] = $request->template_id;
            $data['status'] = $request->status;
            $data['admin'] = $request->ID;
            // 查詢是否存在
            $rows = $model->where('agent_id', $request->agent_id)
                ->where('template_id', $request->template_id)
                ->get();
            if (count($rows) > 0) {
                // 更新
                $result = $model->where('agent_id', $request->agent_id)
                    ->where('template_id', $request->template_id)
                    ->update($data);
            } else {
                // 新增
                $result = $model->create($data);
            }
            if ($result) {
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'agent_template',
                    'list'
                ));
                $redis->delete(array(
                    'template'
                ));
                DB::commit();
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
            DB::rollback();
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
            'agent_id.required' => __('error_message.2017'),
            'agent_id.digits_between' => __('error_message.2018'),

            'template_id.required' => __('error_message.1206'),
            'template_id.numeric' => __('error_message.1207'),

            'status.required' => __('error_message.2009'),
            'status.boolean' => __('error_message.2009'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}