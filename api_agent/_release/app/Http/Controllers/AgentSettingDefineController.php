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
use App\Helpers\SQLHelper;
use App\Models\AgentOperateLogs;
use App\Models\AgentPersonalSettings;
use App\Models\AgentSettingDefine;
use App\Enums\AgentPersonalSettingEnum;
use App\REDIS;
use Hash;
use Validator;
use DB;
use App\Repositories\LanguageTextsRepository;
use App\Repositories\AgentRepository;
use App\Repositories\AgentOperationLogRepository;
use App\Repositories\AgentSettingDefineRepository;


class AgentSettingDefineController extends Controller
{

    /**
    * agent/setting-defines
    * - 取得代理各種設置的定義配置(預設值、多語系、設置類型、資料型別)
    *
    * @response {
    *  "code": 1000,
    *  "message": "SUCCESS", 
    *    
    * }
    */
    public function GetAgentSettingDefines(Request $request)
    {
        try {         

            $LANGUAGE = $request->LANGUAGE;

            $queryCategory = DB::table("agent_setting_defines as def")
                ->where("def.type" ,  1)
                ->select("def.*")
                ->get();

            $query = DB::table("agent_setting_defines as def")
                ->leftJoin("agent_setting_defines as parent" , "def.parent" , "parent.setting_id")
                ->where("def.type" , "!=",  1)
                ->select("def.setting_id" , "def.language_text_code" , "def.data_type" , "def.default_value" , "def.type" , "def.memo" , "def.is_data_required" ,
                         "def.default_value"  , "def.is_visible", "parent.setting_id as parent_id" , "parent.language_text_code as parent_language_text_code")
                ->get();


            $select_all_parent_groups = ArrayHelper::StdClass_ToMultiColumnArrayButGroupByColumn($query , ['parent_id' , 'parent_language_text_code'] ,  'parent_id');
            $allLanguageCodes_1 = ArrayHelper::StdClass_ToSingleColumnDistinctArray($query , 'language_text_code');
            $allLanguageCodes_2 = ArrayHelper::StdClass_ToSingleColumnDistinctArray($query , 'parent_language_text_code');

            $allLanguageCodes = array_merge($allLanguageCodes_1 , $allLanguageCodes_2 );
            
            $rep = new LanguageTextsRepository();

            $queryLanguages = $rep->getTextsByLanguages($LANGUAGE , $allLanguageCodes);

            $result_groups = array();

            foreach ($queryCategory as $key => $each_group) {  

                $new_instance = array();  
                $settingsArray = array();

                $matchLanguage = ArrayHelper::Array_FindItemByColumnValue($queryLanguages , "language_text_code" , $each_group->language_text_code);
                $matchSettings = $query->where("parent_id" , $each_group->setting_id);

                $new_instance["setting_category_id"] = $each_group->setting_id;
                $new_instance["setting_category_language_text_code"] = $each_group->language_text_code;
                $new_instance["is_visible"] = $each_group->is_visible;
                $new_instance["setting_category_name"]=  ArrayHelper::isNotNullNotEmptyArray($matchLanguage) ? $matchLanguage["text"] : $each_group->language_text_code;

               
                foreach ($matchSettings as $key => $eachSetting) 
                {
                    $matchLanguage = ArrayHelper::Array_FindItemByColumnValue($queryLanguages , "language_text_code" , $eachSetting->language_text_code);              
                    $eachSetting->text =   $matchLanguage["text"];
                    
                    array_push($settingsArray , $eachSetting);
                }
            

                $new_instance["settings"]= $settingsArray;
                array_push($result_groups , $new_instance);
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => 
                [
                    "groups" =>  $result_groups
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
    * agent/setting-defines/create
    * - 取得代理各種設置的定義配置(預設值、多語系、設置類型、資料型別)
    *
    * @response {
    *  "code": 1000,
    *  "message": "SUCCESS", 
    *    
    * }
    */
    public function Create(Request $request)
    {
        try {         

            $model = new AgentSettingDefine();           

            $validator = Validator::make($request->all(), $model->rules_create, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $exsitCount = $model->where("setting_id" ,  $request->setting_id)->count();

            if($exsitCount > 0)
            {
                return response()->json([
                    'code' => 2999,
                    'message' => __('error_message.2999'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $insert = array();
            $insert["setting_id"] = $request->setting_id;
            $insert["parent"] = $request->parent;
            $insert["type"] = $request->type;
            $insert["is_visible"] = 1;
            $insert["memo"] = $request->memo;
            $insert["language_text_code"] = $request->language_text_code;
            $insert["default_value"] = $request->default_value;
            $insert["data_type"] = $request->data_type;
            $insert["is_data_required"] = 0;
            $insert["created_by"] = $request->ID;

            $model->create($insert);

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




    /**
    * agent/insert-sql-generate
    * - 產生SQL insert語法提供複製  
    * @response {
    *  "code": 1000,
    *  "message": "SUCCESS", 
    *    
    * }
    */
    public function GenerateInsertSQL(Request $request)
    {
        try {         

            $querySettingDefine = AgentSettingDefine::where("setting_id" , $request->setting_id)->first();

            if(!is_object($querySettingDefine))
            {
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044'),
                    'data' => ""
                ]);
            }

            $result_sqls = array();

            $sql = SQLHelper::ConvertQueryDataToInsertCommand("agent_setting_defines" , $querySettingDefine);      
            
            array_push($result_sqls , $sql);

            $query_language_texts = DB::table("language_texts")
                ->where("language_text_code" , $querySettingDefine->language_text_code)
                ->get();

            foreach ($query_language_texts as $value) {               

                //沒設置文案的話 不產生SQL
                if(!Common::IsNullOrEmptyString($value->text))
                {
                    $insert_sql = SQLHelper::ConvertQueryDataToInsertCommand("language_texts" , $value);                  
                    array_push($result_sqls , $insert_sql);
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' =>  
                [
                    "sql_list" => $result_sqls
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

    

    private function messages()
    {
        return [         
            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
    


}