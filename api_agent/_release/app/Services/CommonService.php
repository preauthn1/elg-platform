<?php

namespace App\Services;

class CommonService
{
    /**
     * 空白檢查
     *
     * @params string $checkStr
     * @return boolean
     */
    public static function isEmpty($checkStr)
    {
        /* @var boolean $checkResult 檢查結果 */
        $checkResult = false;

        $checkStr = trim($checkStr);

        if (empty($checkStr)) {
            $checkResult = true;
        } elseif ('' == $checkStr) {
            $checkResult = true;
        } elseif (0 == $checkStr) {
            $checkResult = true;
        } elseif (false == $checkStr) {
            $checkResult = true;
        }
        return $checkResult;
    }


    /**
     * 字串檢查
     *
     * @param string @$checkStr
     * @param integer $max maximum value
     * @return boolean
     */
    public static function checkStr($checkStr, $max = 12)
    {
        $pattern = "/^[a-z0-9]{6,$max}$/";
        preg_match($pattern, $checkStr, $matches);
        return (0 < count($matches))? true : false;
    }
}
