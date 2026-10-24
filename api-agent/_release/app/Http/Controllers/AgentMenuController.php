<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\AgentMenu;
use App\AgentPermission;
use Validator;
use App\REDIS;

class AgentMenuController extends Controller
{

    /**
     * agentMenu/list
     * -營運商選單列表(只可查看自己及自己的下線)
     *
     * @bodyParam agent_id string required 營運商系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": 1,
     *            "parent": 0,
     *            "code": "member",
     *            "name": "會員管理",
     *            "url": "member",
     *            "symbol": "member",
     *            "action": "create,update",
     *            "submenu":{
     *                 "id": 2,
     *                 "parent": 1,
     *                 "code": "user_list",
     *                 "name": "帳號列表",
     *                 "url": "user/list",
     *                 "symbol": "",
     *                 "action": "list",
     *                 "submenu":{
     *                     "id": 3,
     *                     "parent": 2,
     *                     "code": "user_create",
     *                     "name": "新增帳號",
     *                     "url": "user/create",
     *                     "symbol": "",
     *                     "action": "create"
     *                 }
     *             }
     *        },
     *        {
     *            "id": 4,
     *            "parent": 0,
     *            "code": "report",
     *            "name": "報表管理",
     *            "url": "report",
     *            "symbol": "report",
     *            "action": "list"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        $model = new AgentMenu();
        // 驗證POST資料
        $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
        if (!$validator->passes()) {
            return response()->json([
                'code' => 2002,
                'message' => __('error_message.2002'),
                'data' => $validator->errors()
                    ->all()
            ]);
        }
        $key = array(
            'agent_menus',
            $request->agent_id
        );
        $redis = new REDIS();
        if (!$redis->exists($key)) {
            if ($this->isChild($request->ID, $request->agent_id) || $request->ID == $request->agent_id) {
                $menus = AgentMenu::leftJoin('agent_permissions', 'agent_permissions.agent_menu_id', '=', 'agent_menus.id')
                    ->where('agent_id', $request->agent_id)
                    ->where('status', 1)
                    ->whereNotNull('agent_permissions.id')
                    ->select(
                        'agent_menus.id',
                        'agent_menus.parent',
                        'agent_menus.code',
                        'agent_menus.name',
                        'agent_menus.url',
                        'agent_menus.symbol',
                        'agent_permissions.action'
                    )
                    ->orderBy('agent_menus.parent')
                    ->orderBy('agent_menus.sort')
                    ->orderBy('agent_menus.id')
                    ->get();
                $data = $this->tree($menus);
                $redis->set($key, $data);
            } else {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003')
                ]);
            }
        } else {
            $data = $redis->get($key);
        }
        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000'),
            'data' => $data
        ]);
    }

    /**
     * userMenu/auth
     * -驗證選單權限
     *
     * @bodyParam url string required 選單網址 Example: 1
     * @response {
     *  "code(有權限)": 1000,
     *  "code(沒有權限)": 9003,
     *  "code(選單不存在)": 2039,
     *  "message": "錯誤訊息或Success"
     * }
     */
    public function auth(Request $request)
    {
        try {
            $model = new AgentMenu();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_auth, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // 先確認URL是否存在
            $menu = $model->where('url', $request->url)->first();
            if (is_object($menu)) {
                // 看這個人的角色是否有權限存取
                $permission = AgentPermission::where('agent_id', $request->ID)->where('agent_menu_id', $menu->id)->first();
                if (is_object($permission)) {
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000')
                    ]);
                } else {
                    return response()->json([
                        'code' => 9003,
                        'message' => __('error_message.9003')
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 2039,
                    'message' => __('error_message.2039')
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

    private function tree($data)
    {
        $items = array();
        foreach ($data as $v) {
            $items[$v['id']] = array(
                "id" => $v['id'],
                "parent" => $v['parent'],
                "code" => $v['code'],
                "name" => $v['name'],
                "url" => $v['url'],
                "symbol" => $v['symbol'],
                "action" => $v['action']
            );
        }

        $tree = array();
        foreach ($items as $k => $item) {
            if (isset($items[$item['parent']])) {
                $items[$item['parent']]['submenu'][] = &$items[$k];
            } else {
                $tree[] = &$items[$k];
            }
        }
        return $tree;
    }

    private function messages()
    {
        return [
            'agent_id.required' => __('error_message.2017'),
            'agent_id.digits_between' => __('error_message.2018')
        ];
    }
}