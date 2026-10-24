<?php

namespace target\support\Helpers;

class ArrayHelper
{
    public static function stdClassToSingleColumnArray($stdClass_array, $column_name)
    {
        $select_single_column = $stdClass_array->pluck($column_name);
        $array = json_decode(json_encode($select_single_column, true));
        return $array;
    }

    public static function stdClassToSingleColumnDistinctArray($stdClass_array, $column_name)
    {
        $select_single_column = $stdClass_array->pluck($column_name);
        $array = json_decode(json_encode($select_single_column, true));
        $array = array_unique($array);
        return $array;
    }


    /*
        會根據指定的 column_name_group_by 合併成一筆但會select group 第一筆的指定的多個欄位
    */
    public static function stdClassToMultiColumnArrayButGroupByColumn($stdClass_array, $columns, $column_name_group_by)
    {
        $array = array();
        $existArrayValues = array();

        foreach ($stdClass_array as $item) {
            $instance = array();

            foreach ($columns as $each_column) {
                $instance[ $each_column] = $item->$each_column;
            }

            if (in_array($instance[$column_name_group_by], $existArrayValues)) {
            } else {
                array_push($existArrayValues, $instance[$column_name_group_by]);
                array_push($array, $instance);
            }
        }

        return $array;
    }

    
    public static function arrayFindItemByColumnValue($array, $column_name, $targetValue)
    {
        foreach ($array as $item) {
            if ($item[$column_name] ==  $targetValue) {
                return $item;
            }
        }

        return null;
    }
}
