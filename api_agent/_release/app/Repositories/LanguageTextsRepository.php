<?php

namespace App\Repositories;

use DB;
use App\Models\AgentOperateLogs;
use App\Helpers\Common;

class LanguageTextsRepository extends Repository
{
    /**
     * AgentRepository constructor.
     * @param Agent $model
     */
    public function __construct()
    {
        $this->model = new AgentOperateLogs();
    }

    public function getTextsByLanguages($language_id , $language_codes)
    {   
        $target_language_ids = array();
        array_push($target_language_ids , $language_id);

        //如果無設置該語系的文字，會預設顯示英文的設置
        if($language_id != 1)
        {
            array_push($target_language_ids , 1);
        }

        $query = DB::table("language_texts")
            ->whereIn("language_id" , $target_language_ids)
            ->whereIn("language_text_code" , $language_codes)
            ->select("language_text_code" , "language_id" , "text")
            ->get();

        $result = array();

        foreach ($language_codes as $key => $target_code) {

            $match = $query->where("language_text_code" , $target_code)
                ->sortByDesc("language_id")
                ->first();

            if(!is_object($match) || Common::IsNullOrEmptyString($match->text))
            {
                array_push($result , [  
                    "language_text_code" => $target_code,
                    "text" =>  $target_code,
                 ]);
            }
            else
            {
                array_push($result , [  
                    "language_text_code" => $match->language_text_code,
                    "text" => $match->text,
                 ]);
            }
        }

        return $result;
    }

}