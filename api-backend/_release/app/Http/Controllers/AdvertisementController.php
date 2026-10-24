<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Advertisement;
use App\AdvertisementPath;
use App\REDIS;
use Validator;
use DB;

class AdvertisementController extends Controller
{

    /**
     * advertisement/list
     * -廣告列表
     *
     * @bodyParam path_id integer 類型編號 Example: 1
     * @bodyParam path_name string 類型名稱 Example: index
     * @bodyParam start_time date required 開始日期 Example: 2019-01-01 00:00:00
     * @bodyParam end_time date required 結束日期 Example: 2019-01-31 23:59:59
     * @bodyParam language_id integer 語系編號 Example: 1
     * @bodyParam country_id integer 國家編號 Example: 1
     * @bodyParam agent_id integer 營運商編號 Example: 1
     * @bodyParam status boolean 狀態 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "id": "版位系統編號,ex:1",
     *            "name": "版位名稱,ex:index_top",
     *            "column": "欄位數量,ex:2",
     *            "width": "圖片寬度,ex:640",
     *            "height": "圖片高度,ex:300",
     *            "status": "狀態0:停用1:啟用,ex:1",
     *            "images":  [
     *                        {
     *                            "id": "系統編號,ex:1",
     *                            "language_id": "語系系統編號,ex:1",
     *                            "country_id": "國家系統編號,ex:0",
     *                            "agent_id": "營運商系統編號,ex:0",
     *                            "image": "圖片位置,ex:http://xxx.xxx.xxx/xxx.jpg",
     *                            "image_pc": "PC版圖片位置,ex:http://xxx.xxx.xxx/xxx.jpg",
     *                            "url": "連結網址,ex:http://xxx.xxx.xxx",
     *                            "game_id": "遊戲編號,ex:1",
     *                            "game_type_id": "遊戲類型編號,ex:1",
     *                            "vendor_id": "遊戲商編號,ex:1",
     *                            "start_time": "開始時間,ex:2019-04-01 00:00:00",
     *                            "end_time": "結束時間,ex:2019-04-30 00:00:00",
     *                            "sort": "排序,ex:1"
     *                        }
     *                    ]
     *          }
     *    ]
     * }
     */
    public function list(Request $request)
    {
        try {
            $model = new Advertisement();
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
                'advertisement',
                'list',
                $request->path_id,
                $request->path_name,
                $request->start_time,
                $request->end_time,
                $request->language_id,
                $request->country_id,
                $request->agent_id,
                $request->status
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                // 先取位置，再取內容
                $model = new AdvertisementPath();
                if ($request->path_id != '') {
                    $model = $model->where('id', $request->path_id);
                }
                if ($request->path_name != '') {
                    $model = $model->where('name', 'like', '%' . $request->path_name . '%');
                }
                if (isset($request->status)) {
                    $model = $model->where('status', $request->status);
                }
                $data = $model->select('id', 'name', 'column', 'width', 'height', 'status')->get();
                // 取圖片
                foreach ($data as $_key => $row) {
                    $model_image = new Advertisement();
                    if ($request->start_time != '') {
                        $model_image = $model_image->where('end_time', '>=', $request->start_time);
                    }
                    if ($request->end_time != '') {
                        $model_image = $model_image->where('start_time', '<=', $request->end_time);
                    }
                    if ($request->language_id != '') {
                        $model_image = $model_image->where('language_id', $request->language_id);
                    }
                    if ($request->country_id != '') {
                        $model_image = $model_image->where('country_id', $request->country_id);
                    }
                    if ($request->agent_id != '') {
                        $model_image = $model_image->where('agent_id', $request->agent_id);
                    }
                    $images = $model_image->select('id', 'language_id', 'country_id', 'agent_id', 'image', 'image_pc', 'url', 'game_id', 'game_type_id', 'vendor_id', DB::raw('DATE_ADD(start_time,INTERVAL 8 HOUR) as start_time'), DB::raw('DATE_ADD(end_time,INTERVAL 8 HOUR) as end_time'), 'sort')
                        ->where('path_id', $row->id)
                        ->orderBy('sort', 'asc')
                        ->get();
                    $data[$_key]['images'] = $images;
                }
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
     * advertisement/view
     * -廣告明細
     *
     * @bodyParam id integer required 廣告編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": {
     *      "id": "系統編號,ex:1",
     *      "path_id": "版位系統編號,ex:1",
     *      "language_id": "語系系統編號,ex:1",
     *      "country_id": "國家系統編號,ex:0",
     *      "agent_id": "營運商系統編號,ex:0",
     *      "image": "圖片位置,ex:http://xxx.xxx.xxx/xxx.jpg",
     *      "image_pc": "PC版圖片位置,ex:http://xxx.xxx.xxx/xxx.jpg",
     *      "url": "連結網址,ex:http://xxx.xxx.xxx",
     *      "game_id": "遊戲編號,ex:1",
     *      "game_type_id": "遊戲類型編號,ex:1",
     *      "vendor_id": "遊戲商編號,ex:1",
     *      "sort": "排序,ex:1",
     *      "start_time": "開始時間,ex:2019-04-01 00:00:00",
     *      "end_time": "結束時間,ex:2019-04-30 00:00:00",
     *      "created_at": "建立時間,ex:2019-01-01 00:00:00",
     *      "updated_at": "更新時間,ex:2019-04-31 23:59:59",
     *      "admin": 1
     *   }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Advertisement();
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
                'advertisement',
                $request->id
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $data = $model->where('id', $request->id)->select('id', 'path_id', 'language_id', 'country_id', 'agent_id', 'image', 'image_pc', 'url', 'game_id', 'game_type_id', 'vendor_id', DB::raw('DATE_ADD(start_time,INTERVAL 8 HOUR) as start_time'), DB::raw('DATE_ADD(end_time,INTERVAL 8 HOUR) as end_time'), 'sort')->First();
                if (!$data) {
                    return response()->json([
                        'code' => 1101,
                        'message' => __('error_message.1101')
                    ]);
                }
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
     * advertisement/add
     * -新增廣告
     *
     * @bodyParam path_id integer required 位置系統編號 Example: 1
     * @bodyParam language_id integer required 語系系統編號 Example: 1
     * @bodyParam country_id string 國家系統編號(json格式) Example: {"2","3","4"}
     * @bodyParam agent_id string 營運商系統編號(json格式) Example: {"1"}
     * @bodyParam image string base64 file 廣告圖片
     * @bodyParam image_pc string base64 file PC版廣告圖片
     * @bodyParam url string  點下圖片後的連結位置
     * @bodyParam game_id integer  遊戲系統編號
     * @bodyParam game_type_id integer  遊戲類型系統編號
     * @bodyParam vendor_id integer  遊戲商系統編號
     * @bodyParam sort integer 排序
     * @bodyParam start_time dateTime required 開始日期 Example: 2019-03-01 00:00:00
     * @bodyParam end_time dateTime required 結束日期 Example: 2019-03-31 23:59:59
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
            $model = new Advertisement();
            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $data['path_id'] = $request->path_id;
            $data['language_id'] = $request->language_id;
            $data['country_id'] = $request->country_id;
            $data['agent_id'] = $request->agent_id;
            // 移動圖檔至本地及上傳到GCP
            if (isset($request->image) && $request->image != '') {
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
            if (isset($request->image_pc) && $request->image_pc != '') {
                $path = $this->uploadBase64File($request->image_pc);
                if ($path['code'] != 1000) {
                    return response()->json([
                        'code' => $path['code'],
                        'message' => $path['message']
                    ]);
                }
                $data['image_pc'] = $path['message'];
            }

            $data['url'] = $request->url;
            $data['game_id'] = $request->game_id;
            $data['game_type_id'] = $request->game_type_id;
            $data['vendor_id'] = $request->vendor_id;
            $data['sort'] = $request->sort ? $request->sort : 0;
            $data['start_time'] = date('Y-m-d H:i:s', strtotime($request->start_time . ' -8hour'));
            $data['end_time'] = date('Y-m-d H:i:s', strtotime($request->end_time . ' -8hour'));
            $data['admin'] = $request->ID;
            if ($result = $model->create($data)) {
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'advertisement'
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
     * advertisement/edit
     * -編輯廣告
     *
     * @bodyParam id integer required 系統編號 Example: 1
     * @bodyParam language_id integer 語系系統編號 Example: 1
     * @bodyParam country_id string 國家系統編號(json格式) Example: {"2","3","4"}
     * @bodyParam agent_id string 營運商系統編號(json格式) Example: {"1"}
     * @bodyParam image string base64 file 廣告圖片
     * @bodyParam image_pc string base64 file PC版廣告圖片
     * @bodyParam url string 點下圖片後的連結位置
     * @bodyParam game_id integer 遊戲系統編號
     * @bodyParam game_type_id integer 遊戲類型系統編號
     * @bodyParam sort integer 排序
     * @bodyParam start_time dateTime 開始日期 Example: 2019-03-01 00:00:00
     * @bodyParam end_time dateTime 結束日期 Example: 2019-03-31 23:59:59
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function update(Request $request)
    {
        try {
            $model = new Advertisement();
            if ($row = $model->find($request->id)) {
                $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
                if ($validator->passes()) {
                    $data = array();
                    $data['path_id'] = ($request->path_id != null) ? $request->path_id : $row->type_id;
                    $data['language_id'] = ($request->language_id != null) ? $request->language_id : $row->language_id;
                    $data['country_id'] = isset($request->country_id) ? $request->country_id : $row->country_id;
                    $data['agent_id'] = isset($request->agent_id) ? $request->agent_id : $row->agent_id;
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
                    if (isset($request->image_pc)) {
                        $path = $this->uploadBase64File($request->image_pc);
                        if ($path['code'] != 1000) {
                            return response()->json([
                                'code' => $path['code'],
                                'message' => $path['message']
                            ]);
                        }
                        $data['image_pc'] = $path['message'];
                    }
                    $data['url'] = $request->url ? $request->url : $row->url;
                    $data['game_id'] = isset($request->game_id) ? $request->game_id : $row->game_id;
                    $data['game_type_id'] = isset($request->game_type_id) ? $request->game_type_id : $row->game_type_id;
                    $data['vendor_id'] = isset($request->vendor_id) ? $request->vendor_id : $row->vendor_id;
                    $data['start_time'] = $request->start_time ? date('Y-m-d H:i:s', strtotime($request->start_time . ' -8hour')) : $row->start_time;
                    $data['end_time'] = $request->end_time ? date('Y-m-d H:i:s', strtotime($request->end_time . ' -8hour')) : $row->end_time;
                    $data['admin'] = $request->ID;
                    if ($model->where('id', $request->id)->update($data)) {
                        // 刪除Redis中相關的資料
                        $redis = new REDIS();
                        $redis->delete(array(
                            'advertisement'
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
                        'code' => 2002,
                        'message' => __('error_message.2002'),
                        'data' => $validator->errors()
                            ->all()
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 1101,
                    'message' => __('error_message.1101')
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
            'id.required' => __('error_message.1126'),
            'id.digits_between' => __('error_message.1127'),

            'path_id.required' => __('error_message.1103'),
            'path_id.digits_between' => __('error_message.1104'),

            'language_id.required' => __('error_message.2019'),
            'language_id.digits_between' => __('error_message.2020'),

            'country_id.digits_between' => __('error_message.2022'),

            'agent_id.digits_between' => __('error_message.2018'),

            'image.base64image' => __('error_message.2023'),
            'image_pc.base64image' => __('error_message.2023'),

            'url.url' => __('error_message.1110'),
            'url.between' => __('error_message.1123'),

            'game_id.digits_between' => __('error_message.1124'),

            'game_type_id.digits_between' => __('error_message.1410'),

            'vendor_id.digits_between' => __('error_message.1401'),

            'sort.digits_between' => __('error_message.1125'),

            'start_time.required' => __('error_message.1105'),
            'start_time.date_format' => __('error_message.1106'),

            'end_time.required' => __('error_message.1107'),
            'end_time.date_format' => __('error_message.1108'),
            'end_time.after' => __('error_message.1109'),

            'status.boolean' => __('error_message.1104')
        ];
    }
}