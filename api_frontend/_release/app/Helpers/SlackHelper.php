<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Schema;
use DB;
use App\Models\Language;
use Log;

class SlackHelper
{

    public static $Channel_F2E_Notify = "https://example.invalid/services/T011TNRPVP0/B016M0H50DT/WPGN9xjTEXzqJtyIDXA6kh0G";


    public static function SendMessage_F2E_Notify($msg)
    {        
        self::SendMessage(self::$Channel_F2E_Notify , $msg );
    }


    public static function SendMessage( $channel_url , $msg)
    {
        $post = array();
        $post['text'] = $msg;        


        $post_payload = json_encode($post);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $channel_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, array('payload' => $post_payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $header = array();
        $header['Content-Type'] = "application/json";        


        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($ch);
        curl_close($ch);

        $result =  json_decode($result, true);

        return response()->json([
            'code' => 1000,
            'message' => "success"
        ]);
    }


}