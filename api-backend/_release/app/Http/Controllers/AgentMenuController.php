<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\AgentMenu;
use Validator;
use App\REDIS;

class AgentMenuController extends Controller
{

    /**
     * agentMenu/list
     * -營運商選單列表(沒有傳送agent_id則傳送所有的選單)
     *
     * @bodyParam agent_id integer 營運商系統編號 Example: 1
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
        if (! $validator->passes()) {
            return response()->json([
                'code' => 2002,
                'message' => __('error_message.2002'),
                'data' => $validator->errors()
                    ->all()
            ]);
        }
        $key = array(
            'agent',
            'menu',
            $request->agent_id
        );
        $redis = new REDIS();
        if (! $redis->exists($key)) {
            $menus = AgentMenu::leftJoin('agent_permissions as p', 'p.agent_menu_id', '=', 'agent_menus.id');
            if (isset($request->agent_id)) {
                $menus = $menus->where('agent_id', $request->agent_id);
            }
            $menus = $menus->select('agent_menus.id', 'agent_menus.parent', 'agent_menus.code', 'agent_menus.name', 'agent_menus.url', 'agent_menus.symbol', 'p.action')->get();
            $data = $this->tree($menus);
            $redis->set($key, $data);
        } else {
            $data = $redis->get($key);
        }
        return response()->json([
            'code' => 1000,
            'message' => __('error_message.1000'),
            'data' => $data
        ]);
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
