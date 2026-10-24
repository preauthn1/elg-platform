<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\REDIS;
use App\Vendor;
use Validator;
use DB;

class VendorController extends Controller
{

    /**
     * vendor/list
     * -遊戲商列表
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱,ex:AG",
     *            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *            "image": "遊戲商LOGO,ex:http://xxx.xxx.xx",
     *            "app_icon": "遊戲商app圖示地址,ex:http://xxxxx.xx.xx/xx.jpg"
     *        },
     *        {
     *            "id": 2,
     *            "name": "MG",
     *            "rate": "1.5",
     *            "image": "http://example.invalid.xx",
     *            "app_icon": "http://xxxxx.xx.xx/xx.jpg"
     *        }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $key = array(
                'vendor',
                'list',
                $request->ID,
                $request->LANGUAGE,
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {

                //查詢這個代理開啟的遊戲商
                $vendors = $this->vendorList($request->ID);
                //如果有設定CODE代表有錯誤，就直接拋錯誤訊息
                if (!isset($vendors['code'])) {
                    $data = Vendor::select('id', 'name', 'rate', 'image', 'app_icon')
                        ->where('status', 1)
                        ->whereIn('id', $vendors)
                        ->get();
                    $redis->set($key, $data);
                } else {
                    return response()->json($vendors);
                }
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
     * vendor/username
     * -查詢遊戲商帳號
     * 
     * @bodyParam member_id integer required 會員帳號 Example: 30
     * @bodyParam vendor_id integer required 遊戲商編號
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *            "username": "系統編號,ex:1",
     *            "balance": "平台幣跟遊戲幣的轉換率,ex:10"
     *        }
     * }
     */
    public function username(Request $request)
    {
        try {
            // 驗證POST資料
            $rules = array(
                'member_id' => 'required|digits_between:0,10',
                'vendor_id' => 'required|digits_between:0,10',
            );
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            // $key = array(
            //     'vendor',
            //     'username',
            //     $request->member_id,
            //     $request->vendor_id,
            // );
            // $redis = new REDIS();
            // if (!$redis->exists($key)) {
            //查詢各遊戲商帳號
            $vendor = Vendor::find($request->vendor_id);
            if (is_object($vendor)) {
                $member = DB::table($vendor->code . '_accounts')->where('member_id', $request->member_id)->first();
                if (is_object($member)) {
                    switch ($request->vendor_id) {
                        case 2:
                            $username = env('JOKER_APP_ID') . '.' . $member->username;
                            break;
                        case 3:
                        case 10:
                            $username = $member->player_id;
                            break;
                        case 4:
                            $username = env('HAOLONG_USER') . $member->username;
                            break;
                        case 5:
                            $username = env('GREAT_WALL_API_AGENT') . $member->username;
                            break;
                        default:
                            $username = $member->username;
                            break;
                    }
                    $data = [
                        'username' => $username,
                        'balance' => 0
                    ];
                    // $redis->set($key, $data);
                } else {
                    //查無此玩家遊戲商帳號
                    return response()->json([
                        'code' => 1005,
                        'message' => __('error_message.1005'),
                    ]);
                }
            } else {
                //查無此遊戲商
                return response()->json([
                    'code' => 1426,
                    'message' => __('error_message.1426'),
                ]);
            }
            // } else {
            //     $data = $redis->get($key);
            // }
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
            'member_id.required' => __('error_message.1303'),
            'member_id.digits_between' => __('error_message.1303'),

            'vendor_id.required' => __('error_message.1504'),
            'vendor_id.digits_between' => __('error_message.1505'),
        ];
    }
}