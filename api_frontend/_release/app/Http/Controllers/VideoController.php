<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\VideoTt;
use App\Models\TiktokAccount;
use App\Models\TiktokLog;

class VideoController extends Controller
{
    public function openApp(Request $request)
    {
        $info = $this->getAccount($request->ID);
        $video = new VideoTt();
        $result = $video->getLoginInfo($info->username, $info->password);

        if ($result['code'] == 1000) {

            //寫入登入紀錄
            $data = array();
            $data['member_id'] = $request->ID;
            $data['session_id'] = $result['data']['sessionID'];
            $data['clientID'] = $result['data']['clientID'];
            $dat['admin'] = 0;
            TiktokLog::create($data);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    [
                        'username' => $info->username,
                        'password' => $info->password,
                        'ios_download' => 'https://example.invalid/session/' . $result['data']['sessionID'],
                        'ios_open' => 'https://example.invalid/session/' . $result['data']['sessionID'],
                        'android_download' => 'https://example.invalid/session/' . $result['data']['sessionID'],
                        'android_open' => 'https://example.invalid/session/' . $result['data']['sessionID'],
                        'app_icon' => '',
                    ],
                    [
                        'username' => $info->username,
                        'password' => $info->password,
                        'ios_download' => 'https://example.invalid/session/' . $result['data']['sessionID'],
                        'ios_open' => 'https://example.invalid/session/' . $result['data']['sessionID'],
                        'android_download' => 'https://example.invalid/session/' . $result['data']['sessionID'],
                        'android_open' => 'https://example.invalid/session/' . $result['data']['sessionID'],
                        'app_icon' => '',
                    ],
                    [
                        'username' => $info->username,
                        'password' => $info->password,
                        'ios_download' => 'https://example.invalid/session/' . $result['data']['sessionID'],
                        'ios_open' => 'https://example.invalid/session/' . $result['data']['sessionID'],
                        'android_download' => 'https://example.invalid/session/' . $result['data']['sessionID'],
                        'android_open' => 'https://example.invalid/session/' . $result['data']['sessionID'],
                        'app_icon' => '',
                    ]
                ]
            ]);
        } else {
            return response()->json([
                'code' => 2004,
                'message' => __('error_message.2004'),
            ]);
        }
    }

    private function getAccount($member_id)
    {
        $account = TiktokAccount::where('member_id', $member_id)->first();
        //如果沒有找到帳號就直接綁定空帳號
        if (!is_object($account)) {
            $account = TiktokAccount::where('member_id', 0)->first();
            if (is_object($account)) {
                $account->update(['member_id' => $member_id]);
            } else {
                return response()->json([
                    'code' => 9993,
                    'message' => __('error_message.9993'),
                ]);
            }

        }
        return $account;
    }

    private function getVendorPassword($username, $createTime)
    {
        return substr(md5($username . strtotime($createTime)), 8, 12);
    }
}
