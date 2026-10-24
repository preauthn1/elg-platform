<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Setting;
use Validator;

class SettingController extends Controller
{

    /**
     * setting/view
     * -查看系統設定詳情
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data":{
     *        "name": "聊天室名稱",
     *        "background_image": "聊天室背景圖",
     *        "background_color": "聊天室背景色",
     *        "header_background_color": "Header背景色",
     *        "header_font_color": "Header文字色",
     *        "api_url":"第三方API地址(使用於轉帳，驗證密碼...)"
     *    }
     * }
     */
    public function show(Request $request)
    {
        try {
            $data = Setting::select('name', 'background_image', 'background_color', 'header_background_color', 'header_font_color', 'api_url')->where('agent_id', $request->ID)->first();
            if (is_object($data)) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $data
                ]);
            } else {
                return response()->json([
                    'code' => 1201,
                    'message' => __('error_message.1201')
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
     * setting/save
     * -儲存系統設定
     *
     * @bodyParam name string 聊天室名稱 Example: xxx
     * @bodyParam background_image string 聊天室背景圖(base64 encode)
     * @bodyParam background_color string 聊天室背景色
     * @bodyParam header_background_color string Header背景色
     * @bodyParam header_font_color string Header文字色
     * @bodyParam api_url string API地址
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function save(Request $request)
    {
        try {
            $model = new Setting();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_save, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }
            // 寫入數據庫
            $data = array();
            $data['agent_id'] = $request->ID;
            $data['name'] = $request->name;
            // 移動圖檔至本地及上傳到GCP
            if (isset($request->background_image) && $request->background_image != '') {
                $path = $this->uploadBase64File($request->background_image);
                if ($path['code'] != 1000) {
                    return response()->json([
                        'code' => $path['code'],
                        'message' => $path['message']
                    ]);
                }
                $data['background_image'] = $path['message'];
            }
            $data['background_color'] = $request->background_color;
            $data['header_background_color'] = $request->header_background_color;
            $data['header_font_color'] = $request->header_font_color;
            $data['api_url'] = $request->api_url;
            $data['admin'] = $request->ID;
            // 如果之前儲存過就更新，否則就新增
            if ($row = $model->where('agent_id', $request->ID)->first()) {
                $result = $row->update($data);
            } else {
                $result = $model->create($data);
            }

            if ($result) {
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
            'name.between' => __('error_message.1202'),
            'background_image.base64image' => __('error_message.1203'),
            'background_color.between' => __('error_message.1204'),
            'header_background_color.between' => __('error_message.1205'),
            'header_font_color.between' => __('error_message.1206'),
            'api_url.url' => __('error_message.1207'),
            'api_url.between' => __('error_message.1208')
        ];
    }
}