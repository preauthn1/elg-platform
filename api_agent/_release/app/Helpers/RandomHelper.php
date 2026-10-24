<?php

namespace App\Helpers;

class RandomHelper
{   
    public static function getOrderNoByPrefix($prefix = "OD")
    {               
        $timeOrderNo = self::getOrderTime();
        return $prefix.$timeOrderNo;
    }


    /* 產生 yyyyMMddHHmmssffffff 的時間序號 */
    public static function getOrderTime(){
    
        $micro_date = microtime();
        $date_array = explode(" ",$micro_date);
        $date = date("YmdHis",$date_array[1]);

        $milliseconds = substr(str_replace("0." , "" , $date_array[0]) , 0 , 5);
        return $date .  $milliseconds;
    }

    
    public static function getTransactionNo(){
    
        $number = date('ymdHis') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $is_exist = \App\Models\TransactionLog::whereBetween('created_at', [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')])
            ->where('transaction_no', $number)->count();
        // 驗證不重覆
        if ($is_exist) {
            return self::getTransactionNo();
        } else {
            return $number;
        }
    }


}