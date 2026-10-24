<?php


namespace target\support\Helpers\Utils;

class Utils
{

    /**
     * 判斷是否 json string
     *
     * @param  $string
     * @return bool
     */
    public static function isJSON($string)
    {
        return is_string($string)
            && is_array(json_decode($string, true))
            && (json_last_error() == JSON_ERROR_NONE) ? true : false;
    }
}
