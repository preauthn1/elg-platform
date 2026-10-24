<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\Common;
use App\Models\Advertisement;
use App\Models\AdvertisementPath;
use App\Models\AdvertiseImage;
use App\REDIS;
use Validator;
use DB;

class ImageController extends Controller
{

    /**
     * image/base64/upload
     * - 上傳一張base64圖片 並取得雲端空間路徑
     *
     */
    public function uploadImageToRemote(Request $request)
    {
        try {
            Common::log($request->ID, $request->all(), 'upload_image');
            $result = array();
            if (isset($request->base64Image) && $request->base64Image != '') {
                $path = $this->uploadBase64File($request->base64Image);

                if ($path['code'] != 1000) {
                    return response()->json([
                        'code' => $path['code'],
                        'message' => $path['message']
                    ]);
                }

                $result['path'] = $path['message'];

                //ad直接寫入
                if ($request->type == 1) {
                    $data = array();
                    $data['image_url'] = $path['message'];
                    $data['admin'] = $request->agent_id;
                    $advertise_image = AdvertiseImage::create($data);
                    $result['advertise_image_id'] = $advertise_image->id;
                }
            }

            Common::log(
                $request->ID,
                ['request' => $request->all(), 'response' => $result],
                'upload_image'
            );

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $result
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            Common::log($request->ID, $info, 'upload_image_query_exception');
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            Common::log($request->ID, $e->getMessage(), 'upload_image_exception');
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