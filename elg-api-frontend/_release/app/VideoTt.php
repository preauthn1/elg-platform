<?php

namespace App;

use App\Helpers\VendorSend;
use DB;
use App\Helpers\Common;

class VideoTt
{

    protected $vendor_send;

    function __construct()
    {
        $this->vendor_send = new VendorSend();
    }


    /**
     * -進遊戲
     */
    public function getLoginInfo($username, $password)
    {
        try {
            $url = env('TT_API_URL') . 'auth/login';
            $data = array();
            $data['username'] = $username;
            $data['password'] = sha1($password);
            $body = base64_encode(json_encode($data));

            $postData = array();
            $postData['data'] = $body;

            $result = json_decode($this->vendor_send->send('video_login', $url, 'POST', $postData), true);
            if ($result['status'] == 1) {
                return [
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => ['sessionID' => $result['sessionID'], 'clientID' => $result['clientID']],
                ];
            } else {
                return [
                    'code' => 9996,
                    'message' => $result ? $result : __('error_message.9996')
                ];
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

    private function sign($username, $key)
    {
        return md5($username . $key);
    }
}