<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Agent;
use App\Models\AgentPermission;
use App\Models\AgentMenu;
use App\Models\AgentVendor;
use App\Models\Vendor;
use App\Helpers\Common;
use App\Helpers\ArrayHelper;
use App\Models\AgentOperateLogs;
use App\Models\AgentPersonalSettings;
use App\Enums\AgentPersonalSettingEnum;
use App\Enums\AgentSystemConstant;
use App\REDIS;
use Hash;
use Validator;
use DB;
use App\Repositories\LanguageTextsRepository;
use App\Repositories\AgentRepository;
use App\Repositories\AgentOperationLogRepository;
use App\Repositories\AgentSettingDefineRepository;


class LanguageTextController extends Controller
{


    /**
    * language-text/show
    * -取得多語系文案
    *
    * @response {
    *  "code": 1000,
    *  "message": "SUCCESS", 
    *    
    * }
    */
    public function GetLanguageTextsByCode(Request $request)
    {
        try {         

            $language_code = $request->language_code;
            $LANGUAGE = $request->LANGUAGE;

            if(Common::IsNullOrEmptyString($language_code))
            {
                return response()->json([
                    'code' => 9999,
                    'message' => __('error_message.9999'),
                    'data' => ""
                ]);
            }


            $query = DB::table("language_texts as l")
                ->where("l.language_text_code" , $language_code)
                ->get();

            $query_all_languages = DB::table("languages as l")
                ->select("l.id" , "l.name")
                ->get();

            $supportLanguages = AgentSystemConstant::availableLanguagesInAgentBackend();

            $resultArray = array();

            foreach ($query_all_languages as $key => $each_lang) {               
                
                $new_instance = array();

                $matchData =   $query->where("language_id" ,  $each_lang->id)->first();

                $new_instance["language_id"] = $each_lang->id;
                $new_instance["language_name"] = $each_lang->name;
                $new_instance["value"] =  "";

                if(is_object(  $matchData))
                {
                    $new_instance["value"] = $matchData->text;
                }

                array_push($resultArray , $new_instance);
            }

            //代理後台有支援的語系
            $agentSupportLangs =  $query_all_languages->whereIn("id" ,$supportLanguages);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => 
                [
                    "language_code" => $language_code,
                    "available_languages" =>  $agentSupportLangs,
                    "text_settings" =>  $resultArray
                ]
            ]);


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


    /**
    * language-text/update
    * -更新多語系文案
    *
    * @response {
    *  "code": 1000,
    *  "message": "SUCCESS", 
    *    
    * }
    */
    public function UpdateLanguageTexts(Request $request)
    {
        try {         

            $language_code = $request->language_code;
            $LANGUAGE = $request->LANGUAGE;

            if(Common::IsNullOrEmptyString($language_code))
            {
                return response()->json([
                    'code' => 9999,
                    'message' => __('error_message.9999'),
                    'data' => ""
                ]);
            }

            $query_all_languages = DB::table("languages as l")
                ->select("l.id" , "l.name")
                ->get();

            $supportLanguages = AgentSystemConstant::availableLanguagesInAgentBackend();
            $insertArray = array();
           
            foreach( $query_all_languages as $each_lang)
            {
                $formKey = "language_text_".$each_lang->id;

                $new_instance = array();
                $new_instance["language_text_code"] = $language_code;
                $new_instance["language_id"] = $each_lang->id;
                $new_instance["text"] = $request->$formKey;

                array_push($insertArray , $new_instance);                        
            }

            //先刪除全部
            DB::table("language_texts")->where("language_text_code" ,$language_code)->delete();

            //重新insert全部
            DB::table("language_texts")->insert($insertArray);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => ""
            ]);


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








}