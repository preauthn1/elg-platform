<?php

namespace App\Helpers;

class SQLHelper
{
   
    /* 將eloquent查出的物件(請傳入單筆)，轉成SQL insert 語法 () */
    public static function ConvertQueryDataToInsertCommand($tableName , $query_single_std_class)
    {        
        $convertArray = json_decode(json_encode($query_single_std_class), true);

        $all_properties = array_keys($convertArray);

        $insert_columns = "";
        $insert_values = "";

        $loop_index = 0;

        foreach ($all_properties as $key) {
            
            $comma = ",";

            if($loop_index  == 0)
            {
                $comma = "";    
            }               
            
            if($key == "id" ||  $key == "created_at" || $key == "updated_at")
            {
                continue;
            }

            $insert_columns = $insert_columns." ".$comma." ".$key;
            $insert_values = $insert_values." ".$comma." '".$query_single_std_class->$key."'";
            $loop_index++;
        }

        $finalSQL= "INSERT INTO ".$tableName."(". $insert_columns.")"." VALUES( ".$insert_values." ); ";

        return $finalSQL;
    }



    /* 將eloquent toSql() + getBindings() 轉成SQL */
    public static function ConvertEloquentQueryBinderToSqlCommand($sql , $bindings)
    {        

        $sql_before_replace = str_replace('%' , 'REPLACE_TEMP' ,  $sql);
        $sql_before_replace = str_replace('?' , '%s' ,  $sql_before_replace);

        $newArray = array();

        foreach ($bindings as $value) 
        {
            array_push($newArray  , "'".$value."'" );
        }

        $sql_withParameter = vsprintf($sql_before_replace ,   $newArray);
        $sql_withParameter = str_replace('REPLACE_TEMP' , '%' ,  $sql_withParameter);

        //自動換行 讓轉出來的SQL不要一團亂
        $sql_withParameter = str_replace('group by' , "\r\ngroup by",  $sql_withParameter);
        $sql_withParameter = str_replace('order by' , "\r\norder by",  $sql_withParameter);
        $sql_withParameter = str_replace('from' , "\r\nfrom",  $sql_withParameter);
        $sql_withParameter = str_replace('left join' , "\r\nleft join",  $sql_withParameter);
        $sql_withParameter = str_replace('inner join' , "\r\ninner join",  $sql_withParameter);
        $sql_withParameter = str_replace('where' , "\r\nwhere",  $sql_withParameter);
        $sql_withParameter = str_replace('union' , "\r\nunion",  $sql_withParameter);
    
        return $sql_withParameter;
    }

    public static function getQueries($builder)
    {
        $addSlashes = str_replace('?', "'?'", $builder->toSql());
        return vsprintf(str_replace('?', '%s', $addSlashes), $builder->getBindings());
    }
}