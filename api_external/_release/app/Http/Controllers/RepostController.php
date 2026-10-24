<?php

namespace App\Http\Controllers;

use App\Helpers\Common;
use Illuminate\Http\Request;
use DB;

class RepostController extends Controller
{
    public function getBalance(Request $request)
    {
        try {
            $url = 'api/vendor/balance';

            $token = [REDACTED]'Bearer ', '', $request->header('Authorization'));

            $data = array();
            $data['vendor_id'] = $request->vendor_id;
            $data['vendor_info_id'] = $request->vendor_info_id;
            $data['account'] = $request->account;
            return $this->curlPost($url, $data, 30, $token);
        } catch (\Exception $exception) {
            return response()->json([
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function setTransferIn(Request $request)
    {
        try {
            $url = 'api/vendor/transferIn';

            $token = [REDACTED]'Bearer ', '', $request->header('Authorization'));

            $data = array();
            $data['vendor_id'] = $request->vendor_id;
            $data['vendor_info_id'] = $request->vendor_info_id;
            $data['account'] = $request->account;
            return $this->curlPost($url, $data, 30, $token);
        } catch (\Exception $exception) {
            return response()->json([
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function curlPost($url, $data = '', $timeout = 60, $token = '')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('API_INTERNAL_URL') . $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $header = array();
        if (trim($token) != '') {
            $header[] = "Authorization: Bearer " . $token;
        }curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($ch);
        curl_close($ch);
        return json_decode($result, true);
    }
}