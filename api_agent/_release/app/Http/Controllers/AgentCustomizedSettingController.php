<?php

namespace App\Http\Controllers;

use App\Enums\AgentCustomizedSettingKeyEnum;
use App\Helpers\SQLHelper;
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
use App\Models\AgentCustomizedSetting;
use App\Enums\AgentPersonalSettingEnum;
use App\REDIS;
use Hash;
use Validator;
use DB;
use App\Repositories\LanguageTextsRepository;
use App\Repositories\AgentRepository;
use App\Repositories\AgentOperationLogRepository;
use App\Repositories\AgentSettingDefineRepository;

class AgentCustomizedSettingController extends Controller
{

    /**
     * agent/customized-setting
     * - 取得代理個性化設置
     *
     * @bodyParam id integer 營運商系統編號 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": []
     * }
     */
    public function GetAgentCustomizedSetting(Request $request)
    {
        try {
            $agent_id = isset($request->id) ? $request->id : $request->HOST;

            $data = DB::table('agent_setting_defines as asd')
                ->leftJoin('agent_customized_settings as acs', 'acs.setting_key', 'asd.setting_id')
                ->join('agents as a', function ($join) use ($agent_id) {
                    $join->on('a.customized_setting_group_id', 'acs.agent_group_id')
                        ->where('a.id', $agent_id);
                })
                ->whereRaw("(asd.data_type != 'language_text' or (asd.data_type = 'language_text' and acs.sub_category = 1))")
                ->orderBy('acs.setting_key')
                ->select(
                    'asd.setting_id as setting_key',
                    'asd.memo',
                    DB::raw('IFNULL(acs.value, asd.default_value) AS value')
                )
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
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
    * agent/customized-settings
    * - 取得代理個性化設置
    *
    * @response {
    *  "code": 1000,
    *  "message": "SUCCESS", 
    *    
    * }
    */
    public function GetAgentCustomizedSettings(Request $request)
    {
        try {         

            $LANGUAGE = $request->LANGUAGE;

            $groupID = $request->group_id;

            $queryGroup = DB::table("agent_groups as g")
                ->where("g.id" , $groupID)
                ->select("g.*")
                ->first();

            //確認是否有開啟這個組別的權限
            $checkAgentGroupPermission = $this->checkAgentGroupPermission($groupID, $request->ID);
            //Admin跟Admin的子帳號不檢查
            if($request->HOST != 1 && !$checkAgentGroupPermission) {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003'),
                    'data' => ""
                ]);
            }

            if($queryGroup->agent_group_type != 1)
            {
                return response()->json([
                            'code' => 9999,
                            'message' => __('error_message.9999'),
                            'data' => ""
                        ]);                
            }

            //編輯頁顯示可編輯的欄位 都是依賴 agent_setting_defines 這張表的定義 (type = 3)
            $query = DB::table("agent_setting_defines as def")
                ->leftJoin("agent_setting_defines as parent" , "def.parent" , "parent.setting_id")
                ->where("def.type" , 3)
                ->where("def.is_visible" , "1")
                ->select("def.setting_id" ,  "def.language_text_code" , "def.data_type" , "def.default_value" ,
                         "def.default_value" , "parent.setting_id as parent_id" , "parent.language_text_code as parent_language_text_code")
                ->get();

            $querySettings = DB::table("agent_customized_settings as s")
                ->where("s.agent_group_id" , $groupID)
                ->select("s.*")
                ->get();

            $query_all_languages = DB::table("languages as l")
                ->select("l.id" , "l.name")
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
                $settingsArray = array();

                $matchLanguage = ArrayHelper::Array_FindItemByColumnValue($queryLanguages , "language_text_code" , $each_group["parent_language_text_code"]);
                $matchSettings =  $query->where("parent_id" , $each_group["parent_id"]);

                $new_instance["setting_category_id"] = $each_group["parent_id"];
                $new_instance["setting_category_name"]= $matchLanguage["text"];

                foreach ($matchSettings as $key => $eachSetting) 
                {
                    $matchLanguage = ArrayHelper::Array_FindItemByColumnValue($queryLanguages , "language_text_code" , $eachSetting->language_text_code);    
                    
                    $matchSingleValue = $querySettings
                        ->where("setting_key" , $eachSetting->setting_id)
                        ->first();

                    $eachSetting->value = "";

                    //如果非語系類的設置 只會有一筆 matchSingleValue
                    if(is_object($matchSingleValue))
                    {
                        $eachSetting->value = $matchSingleValue->value;
                    }

                    $eachSetting->text = $matchLanguage["text"];

                    //如果是語系文字設置 要先把所有language foreach然後轉成編輯頁需要的array型式
                    if($eachSetting->data_type == "language_text")
                    {
                        $languageValues = array();

                        foreach ($query_all_languages as $each_language) 
                        {
                            $target_lang = $each_language->id;
                            $target_setting =  $eachSetting->setting_id;

                            $matchSettingOfSubcategory = $querySettings
                                ->where("sub_category" , $each_language->id)
                                ->where("setting_key" , $eachSetting->setting_id)
                                ->first();

                            $value = $eachSetting->default_value;

                            if(is_object($matchSettingOfSubcategory))
                            {
                                $value = $matchSettingOfSubcategory->value;
                            }

                            $new_instance_value = 
                            [
                                "language_id" => $each_language->id,
                                "language_name" => $each_language->name,
                                "language_value" => $value
                            ];

                            array_push($languageValues , $new_instance_value);
                        }

                        $eachSetting->languageValues = $languageValues;

                    }

                    //如果是時間類型的欄位要轉+8
                    if(isset($matchSingleValue->value) && $eachSetting->data_type == "datetime")
                    {
                        $eachSetting->value = date('Y-m-d H:i:s', strtotime($eachSetting->value . '+8hour'));
                    }

                    if(!isset($matchSingleValue->value))
                    {
                        $eachSetting->value = $this->GetDefaultValueByDefine($eachSetting->default_value);
                    }

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
                    "group_id" => $queryGroup ->id,
                    "group_name" =>  $queryGroup ->name,
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
    * agent/customized-settings/update
    * -更新代理進階設置
    *
    * @response {
    *  "code": 1000,
    *  "message": "SUCCESS", 
    *    
    * }
    */
    public function UpdateAgentCustomizedSettings(Request $request)
    {
        try {
            $group_id = $request->group_id;
            $setting_ids_comma = $request->setting_ids;
            $setting_ids_array = explode(',', $setting_ids_comma);           

            $form_post_keys = array();

            //先取出所有form post 表單的資訊
            foreach ($request->except('_token') as $key => $part) {
                if ($this->IsKeywordExist($key, 'form_')) {
                    array_push($form_post_keys, $key);
                }
            }

            if (count($setting_ids_array) <= 0) {
                return response()->json([
                    'code' => 9999,
                    'message' => __('error_message.9999'),
                    'data' => ''
                ]);
            }

            $valid = $this->CheckFormValueIsValid($request, $setting_ids_array);

            if (!$valid["is_valid"]) {
                return response()->json([
                    'code' => 9999,
                    'message' => $valid["message"],
                    'data' => ''
                ]);
            }

            $queryExist = AgentCustomizedSetting::where('agent_group_id', $group_id)
                ->whereIn('setting_key', $setting_ids_array)
                ->get();
                
            foreach ($setting_ids_array as $item) {
                //傳來的表單欄位是 form_{編號} 這種格式，需要另外解析處理
                $expectColumnName = 'form_' . $item;
                $insert = array();
                $insert['agent_group_id'] = $group_id;
                $insert['setting_key'] = $item;
                $insert['sub_category'] = '';

                if (in_array($expectColumnName, $form_post_keys)) {
                    $insert['value'] = $request->$expectColumnName;

                    $matchExist = $queryExist->where('setting_key', $item)->first();
    
                    if (is_object($matchExist)) {
                        $matchExist->update($insert);
                    } else {
                        AgentCustomizedSetting::create($insert);
                    }
                } else {
                    foreach ($form_post_keys as $each_key) {
                        //如果是語系文字的設置類型，會包含這樣的關鍵字&條件
                        if ($this->IsKeywordExist($each_key, $expectColumnName) && 
                            $this->IsKeywordExist($each_key, '_language_'))
                        {
                            //傳來的表單欄位是 form_{編號}__language_{語系編號} 這種格式
                            //需要另外解析處理 
                            $splitText = explode('_language_', $each_key);
                            $subCategory = $splitText[1];

                            $insert['sub_category'] = $subCategory;
                            $insert['value'] = $request->$each_key;

                            $matchExist = $queryExist
                                ->where('setting_key', $item)
                                ->where('sub_category', $subCategory)
                                ->first();
    
                            if (is_object($matchExist)) {
                                $matchExist->update($insert);
                            } else {
                                AgentCustomizedSetting::create($insert);
                            }
                        }
                    }
                }
            }

            //部分redis設置要刪除
            $redis = new REDIS();
            $redis->deleteByKeyword('domain_v');

            //刪除 聯繫客服網址
            if (in_array(AgentCustomizedSettingKeyEnum::CS_CUSTOM_SERVICE_ICON_LINK_URL, $setting_ids_array)) {
                $key = array(
                    'instant_messaging',
                    $group_id
                );
                $redis->delete($key);
            }

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
    * agent/assign-default-value
    * - 將未配置設置的組別，塞DB資料、並填入預設值
    *
    * @response {
    *  "code": 1000,
    *  "message": "SUCCESS", 
    *    
    * }
    */
    public function AutoAssignSettingDefaultValue(Request $request)
    {
        try {         
            $settingID = $request->setting_id;
            
            //$settingID = 4052;

            $queryExistGroupIDs = DB::table("agent_customized_settings")
                ->where("setting_key" , $settingID)
                ->pluck("agent_group_id");

            $queryNotSettingGroups = DB::table("agent_groups")
                ->where("agent_group_type" , 1)
                ->whereNotIn("id" ,  $queryExistGroupIDs)
                ->pluck("id");

            $querySettingDefine = DB::table("agent_setting_defines")
                ->where("setting_id" , $settingID)
                ->first();

            foreach($queryNotSettingGroups as $item)
            {
                $insert = array();

                $insert["agent_group_id"] = $item;
                $insert["setting_key"] = $settingID;
                $insert["sub_category"] = "";
                $insert["value"] = $querySettingDefine->default_value;
                $insert["memo"] = "";

                AgentCustomizedSetting::create($insert);
            }

            $msg = "完成！本次共處理".count($queryNotSettingGroups)."筆資料。";;

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $msg 
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
    




    private function IsKeywordExist($text ,  $keyword)
    {
        return strpos($text, $keyword) !== false;
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

    private function checkAgentGroupPermission($agent_group_id, $agent_id)
    {
        $result = false;
        $agent_group_permissions = DB::table('agent_group_permissions')
            ->where('agent_id', $agent_id)
            ->where('agent_group_id', $agent_group_id)
            ->first();
        if(is_object($agent_group_permissions)) {
            $result = true;
        }

        return $result;
    }


}