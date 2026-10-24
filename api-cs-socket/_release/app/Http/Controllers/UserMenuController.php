<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\UserMenu;
use Validator;
use App\REDIS;
use App\RolePermission;

class UserMenuController extends Controller
{

    /**
     * userMenu/list
     * -管理者選單列表
     *
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
     *            "target": "_blank",
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
        $key = array(
            'user',
            'menu',
            $request->ROLE
        );
        // 先檢查有沒有權限查看
        $redis = new REDIS();
        if (!$redis->exists($key)) {
            $menus = UserMenu::leftJoin('role_permissions as p', 'p.menu_id', '=', 'user_menus.id')->where('role_id', $request->ROLE)
                ->select('user_menus.id', 'user_menus.parent', 'user_menus.code', 'user_menus.name', 'user_menus.url', 'user_menus.symbol', 'p.action', 'user_menus.target')
                ->orderBy('user_menus.id')
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
     *  "message": "錯誤訊息或Success",
     *  "data": "在此選單擁有的權限，EX:add,edit"
     * }
     */
    public function auth(Request $request)
    {
        try {
            $model = new UserMenu();
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
                $permission = RolePermission::where('role_id', $request->ROLE)->where('menu_id', $menu->id)->first();
                if (is_object($permission)) {
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                        'data' => $permission->action,
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
                "action" => $v['action'],
                "target" => $v['target']
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
            'url.required' => __('error_message.2037'),
            'url.between' => __('error_message.2038'),
        ];
    }
}