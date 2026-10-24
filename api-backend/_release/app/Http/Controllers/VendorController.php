<?php

namespace App\Http\Controllers;

use App\REDIS;
use App\Vendor;
use Illuminate\Http\Request;
use Validator;

class VendorController extends Controller
{

    /**
     * vendor/list
     * -遊戲商列表
     *
     * @bodyParam status boolean 狀態 Example: 1
     * 
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "name": "名稱,ex:AG",
     *            "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *            "percentage": "拆帳百分比,ex:10",
     *            "image": "圖片地址,ex:http://xxxxx.xx.xx/xx.jpg",
     *            "app_icon": "遊戲商app圖示地址,ex:http://xxxxx.xx.xx/xx.jpg",
     *            "sort": "排序(數字由小排到大),ex:1",
     *            "status": "狀態(0:停用1:啟用2:開發中),ex:1"
     *        },
     *        {
     *            "id": 2,
     *            "name": "MG",
     *            "rate": "1.5",
     *            "percentage": 5,
     *            "image": "http://xxxxx.xx.xx/xx.jpg",
     *            "app_icon": "http://xxxxx.xx.xx/xx.jpg",
     *            "sort": 2,
     *            "status": 1
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
                $request->status,
                $request->LANGUAGE
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $model = Vendor::select('id', 'name', 'rate', 'percentage', 'image', 'app_icon', 'sort', 'status', 'valid_bet_rate');
                if (isset($request->status) && $request->status != '') {
                    $model = $model->where('status', $request->status);
                }
                $data =  $model->get();
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
     * vendor/view
     * -遊戲商列表
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *        "id": "系統編號,ex:1",
     *        "name": "名稱,ex:AG",
     *        "rate": "平台幣跟遊戲幣的轉換率,ex:10",
     *        "percentage": "拆帳百分比,ex:10",
     *        "image": "圖片地址,ex:http://xxxxx.xx.xx/xx.jpg",
     *        "app_icon": "遊戲商app圖示地址,ex:http://xxxxx.xx.xx/xx.jpg",
     *        "sort": "排序(數字由小排到大),ex:1",
     *        "status": "狀態(0:停用1:啟用2:開發中),ex:1"
     *    }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Vendor();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'vendor',
                $request->id
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $data = Vendor::select('id', 'name', 'rate', 'percentage', 'image', 'app_icon', 'sort', 'status', 'valid_bet_rate')->find($request->id);
                if (is_object($data)) {
                    $redis->set($key, $data);
                } else {
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000')
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
     * vendor/add
     * -新增遊戲商
     *
     * @bodyParam name string required 遊戲商名稱 Example: AG
     * @bodyParam rate flaot required 域名 Example: 1.5
     * @bodyParam percentage flaot required 域名 Example: 30
     * @bodyParam image string required base64 file 廣告圖片
     * @bodyParam app_icon string required base64 file 遊戲商app icon
     * @bodyParam status integer required 狀態(0停用,1正常,2開發中) Example: 1
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
            $model = new Vendor();
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $data['name'] = $request->name;
            $data['rate'] = $request->rate;
            $data['percentage'] = $request->percentage;
            $data['valid_bet_rate'] = $request->valid_bet_rate;
            // 移動圖檔至本地及上傳到GCP
            if (isset($request->image)) {
                $path = $this->uploadBase64File($request->image);
                if ($path['code'] != 1000) {
                    return response()->json([
                        'code' => $path['code'],
                        'message' => $path['message']
                    ]);
                }
                $data['image'] = $path['message'];
            }
            // 移動圖檔至本地及上傳到GCP
            if (isset($request->app_icon)) {
                $path = $this->uploadBase64File($request->app_icon);
                if ($path['code'] != 1000) {
                    return response()->json([
                        'code' => $path['code'],
                        'message' => $path['message']
                    ]);
                }
                $data['app_icon'] = $path['message'];
            }
            $data['sort'] = isset($request->sort) ? $request->sort : 0;
            $data['status'] = isset($request->status) ? $request->status : 0;
            $data['admin'] = $request->ID;
            if ($result = $model->create($data)) {
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'vendor'
                ));
                $redis->delete(array(
                    'agentVendor'
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
     * vendor/edit
     * -編輯遊戲商
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam name string  遊戲商名稱 Example: AG
     * @bodyParam rate flaot  匯率 Example: 1.5
     * @bodyParam percentage flaot  分成比 Example: 30
     * @bodyParam image string  base64 file 廣告圖片
     * @bodyParam app_icon string  base64 file 遊戲商app icon
     * @bodyParam sort integer  排序 Example: 2
     * @bodyParam status integer  狀態(0停用,1正常,2開發中) Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new Vendor();
            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            if ($row = $model->find($request->id)) {
                $data = array();
                $data['name'] = isset($request->name) ? $request->name : $row->name;
                $data['rate'] = isset($request->rate) ? $request->rate : $row->rate;
                $data['percentage'] = isset($request->percentage) ? $request->percentage : $row->percentage;
                $data['valid_bet_rate'] = isset($request->valid_bet_rate) ? $request->valid_bet_rate : $row->valid_bet_rate;
                // 移動圖檔至本地及上傳到GCP
                if (isset($request->image)) {
                    $path = $this->uploadBase64File($request->image);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $data['image'] = $path['message'];
                }
                // 移動圖檔至本地及上傳到GCP
                if (isset($request->app_icon)) {
                    $path = $this->uploadBase64File($request->app_icon);
                    if ($path['code'] != 1000) {
                        return response()->json([
                            'code' => $path['code'],
                            'message' => $path['message']
                        ]);
                    }
                    $data['app_icon'] = $path['message'];
                }
                $data['sort'] = isset($request->sort) ? $request->sort : $row->sort;
                $data['status'] = isset($request->status) ? $request->status : $row->status;
                $data['admin'] = $request->ID;
                if ($model->where('id', $request->id)->update($data)) {
                    // 刪除Redis中相關的資料
                    $redis = new REDIS();
                    $redis->delete(array(
                        'vendor'
                    ));
                    $redis->delete(array(
                        'agentVendor'
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
            'id.required' => __('error_message.1415'),
            'id.digits_between' => __('error_message.1416'),

            'name.required' => __('error_message.1427'),
            'name.between' => __('error_message.1428'),

            'rate.required' => __('error_message.1429'),
            'rate.float' => __('error_message.1430'),

            'percentage.required' => __('error_message.1431'),
            'percentage.float' => __('error_message.1432'),
            
            'valid_bet_rate.required' => __('error_message.1434'),
            'valid_bet_rate.float' => __('error_message.1435'),
            
            'image.required' => __('error_message.2023'),
            'image.base64image' => __('error_message.2023'),

            'status.required' => __('error_message.2009'),
            'status.in' => __('error_message.2022')
        ];
    }
}