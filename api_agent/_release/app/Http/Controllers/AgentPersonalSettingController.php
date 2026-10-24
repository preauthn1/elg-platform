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
use App\REDIS;
use Hash;
use Validator;
use DB;
use App\Repositories\LanguageTextsRepository;
use App\Repositories\AgentRepository;
use App\Repositories\AgentOperationLogRepository;
use App\Repositories\AgentSettingDefineRepository;


class AgentPersonalSettingController extends Controller
{


    /**
    * agent/personal-settings
    * -取得代理進階設置
    *
    * @response {
    *  "code": 1000,
    *  "message": "SUCCESS", 
    *    
    * }
    */
    public function GetAgentPersonalSettings(Request $request)
    {
        try {         

            $LANGUAGE = $request->LANGUAGE;
            $agentID = $request->agent_id;


            $agentRep = new AgentRepository();
            $queryTargetAgent =  $agentRep->getById($agentID);
            $isViewPermission =  $agentRep->isViewPermission($request->HOST ,$agentID);

            if(!is_object($queryTargetAgent) || !$isViewPermission)
            {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003'),
                    'data' => ""
                ]);
            }

            $query = DB::table("agent_setting_defines as def")
                ->leftJoin("agent_personal_settings as s" , function ($join) use ($agentID) 
                    {
                        $join->on('def.setting_id', 's.setting_id')->where('s.agent_id', $agentID);
                    })
                ->leftJoin("agent_setting_defines as parent" , "def.parent" , "parent.setting_id")
                ->where("def.type" , 2)
                ->where("def.is_visible" , "1")
                ->where(function ($query) use ($agentID) {
                    $query->whereNull('def.enable_agents');    
                    $query->orwhere('def.enable_agents', 'like', '%'.$agentID.'%'); //僅限定特定代理ID可開放編輯 (ps. 其實這語法...在位數少的代理編號會有問題)
                })
                ->select("id" , "s.agent_id" , "def.setting_id" , "s.sub_category" , "s.value" , "def.enable_agents" , "def.language_text_code" , "def.data_type" , "def.default_value" ,
                         "def.default_value" , "parent.setting_id as parent_id" , "parent.language_text_code as parent_language_text_code")
                ->get();


            $select_all_parent_groups = ArrayHelper::StdClass_ToMultiColumnArrayButGroupByColumn($query , ['parent_id' , 'parent_language_text_code'] ,  'parent_id');
            $allLanguageCodes_1 = ArrayHelper::StdClass_ToSingleColumnDistinctArray($query , 'language_text_code');
            $allLanguageCodes_2 = ArrayHelper::StdClass_ToSingleColumnDistinctArray($query , 'parent_language_text_code');

            $allLanguageCodes = array_merge($allLanguageCodes_1 , $allLanguageCodes_2 );
            
            $rep = new LanguageTextsRepository();
            $queryLanguages = $rep->getTextsByLanguages( $LANGUAGE , $allLanguageCodes);

            $result_groups = array();

            foreach ($select_all_parent_groups as $key => $each_group) {  

                $new_instance = array();

                $matchLanguage = ArrayHelper::Array_FindItemByColumnValue($queryLanguages , "language_text_code" , $each_group["parent_language_text_code"]);
                $matchSettings =  $query->where("parent_id" , $each_group["parent_id"]);

                $new_instance["setting_category_id"] = $each_group["parent_id"];
                $new_instance["setting_category_name"]= $matchLanguage["text"];

                foreach ($matchSettings as $key => $eachSetting) 
                {
                    $matchLanguage = ArrayHelper::Array_FindItemByColumnValue($queryLanguages , "language_text_code" , $eachSetting->language_text_code);              
                    $eachSetting->text =   $matchLanguage["text"];

                    //如果是時間類型的欄位要轉+8
                    if(isset($eachSetting->value) && $eachSetting->data_type == "datetime")
                    {
                        $eachSetting->value = date('Y-m-d H:i:s', strtotime($eachSetting->value . '+8hour'));
                    }

                    if(!isset($eachSetting->value))
                    {
                        $eachSetting->value = $this->GetDefaultValueByDefine($eachSetting->default_value);
                    }
                }

                $new_instance["settings"]= $matchSettings;

                array_push($result_groups , $new_instance);
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => 
                [
                    "agent_id" => $queryTargetAgent->id,
                    "agent_name" =>  $queryTargetAgent->username,
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
    * agent/personal-settings/update
    * -更新代理進階設置
    *
    * @response {
    *  "code": 1000,
    *  "message": "SUCCESS", 
    *    
    * }
    */
    public function UpdateAgentPersonalSettings(Request $request)
    {
        try {         

            $agentID = $request->agent_id;

            $setting_ids_comma = $request->setting_ids;
            $setting_ids_array = explode(',', $setting_ids_comma);            


            if(count( $setting_ids_array) <= 0)
            {
                return response()->json([
                    'code' => 9999,
                    'message' => __('error_message.9999'),
                    'data' => ""
                ]);
            }

            $valid = $this->CheckFormValueIsValid($request , $setting_ids_array);

            if( !$valid["is_valid"])
            {
                return response()->json([
                    'code' => 9999,
                    'message' => $valid["message"],
                    'data' => ""
                ]);
            }

            $queryExist = AgentPersonalSettings::where("agent_id" ,$agentID )
                ->whereIn("setting_id" ,$setting_ids_array )
                ->get();
                
            foreach($setting_ids_array as $item)
            {               
                //傳來的表單欄位是 form_{編號}
                $expectColumnName = "form_".$item;
                $insert = array();
                $insert["agent_id"] = $agentID;
                $insert["setting_id"] = $item;
                $insert["sub_category"] = '';
                $insert["value"] = $request->$expectColumnName;    

                $matchExist =  $queryExist->where("setting_id" ,$item )->first();

                if(is_object($matchExist))
                {
                    $matchExist ->update($insert);
                }
                else
                {
                    AgentPersonalSettings::create( $insert);
                }
            }

            //部分redis設置要刪除
            $redis = new REDIS();
            $redis->delete(array("maintain_agents"));
            $redis->delete(array("maintain_agents_v1"));
            $redis->delete(array("maintain_agents_v2"));
            $redis->delete(array("maintain_agents_v3"));
            $redis->delete(array("maintain_agents_v4"));
            $redis->delete(array("maintain_agents_v5"));


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

    private function CheckFormValueIsValid($request ,  $setting_ids_array)
    {
        $rep = new AgentSettingDefineRepository();

        $query = $rep->GetBySettingIDs($setting_ids_array);

        foreach ($query as $key => $item) {
           
            $expectColumnName = "form_".$item->setting_id;
            $formValue = $request->$expectColumnName;

            if($item->is_data_required)
            {
                if(!isset($formValue) || $formValue == null || $formValue == '')
                {
                    return
                    [
                        "is_valid" => false,
                        "message" => $item->language_text_code." is required."
                    ];
                }
            }

            $data_type = $item->data_type;

            switch ($data_type) {
                case "datetime":

                    if(!strtotime($formValue))
                    {
                        return
                        [
                            "is_valid" => false,
                            "message" => $item->language_text_code." format error."
                        ];
                    }

                    //強制轉成+0時區
                    $parseUTC = date('Y-m-d H:i:s', strtotime( $request->$expectColumnName . '-8hour'));
                    $request->$expectColumnName =  $parseUTC;
                    break;
                case "boolean":

                    if($formValue != "0" && $formValue != "1")
                    {
                        return
                        [
                            "is_valid" => false,
                            "message" => $query->language_text_code." format error."
                        ];
                    }

                    break;
                default:
                    break;
            }
        }

        return
        [
            "is_valid" => true,
            "message" => ""
        ];
    }


    private function GetDefaultValueByDefine($default_value)
    {
        if(!isset($default_value))
            return null;

        if($default_value == 'TODAY')
            return date('Y-m-d 00:00', strtotime('+0hour'));

        return $default_value;
    }



}