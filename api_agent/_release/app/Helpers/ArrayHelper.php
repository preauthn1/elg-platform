<?php

namespace App\Helpers;

class ArrayHelper
{
   
    public static function StdClass_ToSingleColumnArray($stdClass_array , $column_name)
    {        
        $select_single_column = $stdClass_array->pluck($column_name);    
        $array = json_decode(json_encode($select_single_column, true));
        return $array;
    }

    public static function StdClass_ToSingleColumnDistinctArray($stdClass_array , $column_name)
    {        
        $select_single_column = $stdClass_array->pluck($column_name);    
        $array = json_decode(json_encode($select_single_column, true));
        $array = array_unique($array);
        return $array;
    }

    


    public static function StdClass_ToMultiColumnArrayButGroupByColumn($stdClass_array , $columns , $column_name_group_by)
    {       
        $array = array();
        $existArrayValues = array();

        foreach ($stdClass_array as $key => $item) {
           
            $instance = array();

            foreach ($columns as $key => $each_column) {               
                $instance[ $each_column] = $item->$each_column;
            }

            if(in_array($instance[$column_name_group_by] , $existArrayValues))
            {

            }
            else 
            {
               array_push(  $existArrayValues , $instance[$column_name_group_by]);
               array_push( $array , $instance);
            }
        }

        return $array;
    }

    
    public static function Array_FindItemByColumnValue($array , $column_name , $targetValue)
    {        
        foreach ($array as $key => $item) {
            if($item[$column_name] ==  $targetValue)
            {
                return $item;
            }
        }

        return null;
    }

    public static function isNullOrEmptyArray($array)
    {         
        return !ArrayHelper::isNotNullNotEmptyArray($array);
    }


    public static function isNotNullNotEmptyArray($array)
    {      
        if(is_null($array))
            return false;

        if(is_object($array))
        {
            $total = count((array)$array);
            return $total > 0;
        }

        if(!is_array($array))
            return false;

        return count($array) > 0;           
    }



}