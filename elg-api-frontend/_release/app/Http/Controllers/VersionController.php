<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Version;

/**
 * @group Version
 */
class VersionController extends Controller
{

    /**
     * versions
     * - app本(Header不需要token)
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *        "ios" : {
     *            "version": "正式版本號,ex:1.0.0",
     *            "version_1": "臨時版本號,ex:1.0.0",
     *            "downloadLink": "下載連結,ex:http://example.invalid",
     *        },
     *         "android" : {
     *            "version": "正式版本號,ex:1.0.0",
     *            "version_1": "臨時版本號,ex:1.0.0",
     *            "downloadLink": "下載連結,ex:http://example.invalid",
     *    }
     * }
     */
    public function getVersion(Request $request)
    {
        try {
            $model = new Version();

            $version = $model->where('type', 1)
                ->join('agent_domains as ad', function ($join) use ($request) {
                    $join->on('ad.agent_id', 'versions.agent_id')
                        ->where('ad.domain', $request->domain);
                })
                ->select(
                    'versions.ios_version as ios_version',
                    'versions.ios_link as ios_link',
                    'versions.android_version as android_version',
                    'versions.android_link as android_link'
                )
                ->first();
            if (!is_object($version)) {
                $version = $model->where('type', 1)
                    ->select(
                        'ios_version as ios_version',
                        'ios_link as ios_link',
                        'android_version as android_version',
                        'android_link as android_link'
                    )
                    ->first();
            }
            $data = array();
            $data['ios'] = array(
                'version' => $version->ios_version,
                'downloadLink' => $version->ios_link
            );
            $data['android'] = array(
                'version' => $version->android_version,
                'downloadLink' => $version->android_link
            );
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
}
