<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\UserMenu;
use Validator;
use App\REDIS;

class UserMenuController extends Controller
{

    /**
     * userMenu/list
     * -管理者選單列表(只可查看自己及自己的下線)
     *
     * @bodyParam user_id integer required 管理者系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *        {
     *            "id": 1,
     *            "parent": 0,
     *            "code": "user",
     *            "name": "帳號管理",
     *            "url": "user",
     *            "symbol": "user",
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
        $model = new UserMenu();
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
            'user',
            'menu',
            $request->user_id
        );
        // 先檢查有沒有權限查看
        if ($this->isChild($request->ID, $request->user_id) || $request->ID == $request->user_id) {
            $redis = new REDIS();
            if (! $redis->exists($key)) {
                $menus = UserMenu::leftJoin('user_permissions', 'user_permissions.menu_id', '=', 'user_menus.id')->where('user_id', $request->user_id)
                    ->select('user_menus.id', 'user_menus.parent', 'user_menus.code', 'user_menus.name', 'user_menus.url', 'user_menus.symbol', 'user_permissions.action')
                    ->get();
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
        } else {
            return response()->json([
                'code' => 9003,
                'message' => __('error_message.9003')
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
            'user_id.required' => __('error_message.1057'),
            'user_id.digits_between' => __('error_message.1052')
        ];
    }
}
