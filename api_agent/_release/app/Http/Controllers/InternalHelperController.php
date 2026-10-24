<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Member;
use App\Models\Agent;
use App\Models\AgentPermission;
use App\Models\AgentMenu;
use App\Models\AgentVendor;
use App\Models\Vendor;
use App\Helpers\Common;
use App\Models\AgentOperateLogs;
use App\REDIS;
use Hash;
use Validator;
use DB;
use App\Repositories\AgentRepository;
use App\Services\CSDataSyncService;
use App\Helpers\RandomHelper;
use App\ThirdPartyApi\ATPayIntegrationHelper;




class InternalHelperController extends Controller
{   
      /**
     * internal-helper/redis/flushDB
     * 清空redis 
     */
    public function redis_flushDB(Request $request)
    {            
        try{            
            $redis = new REDIS();
            $redis->flushDb();
          
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


    /*
        /data-convert/php-lang/to/excel
        讀取語系檔並轉成Excel格式
    */
    public function ReadLanguageResourceAndConvertToExcelFormat(Request $request)
    {      
        $time_1 = gmdate('Y-m-d h:i:s');

        try {         
            //定義有哪些語系
            $langs = [ "en" , "zh-tw" , "zh-cn" , "vi-vn"];
            
            //定義有哪些語系檔案
            $i18nFiles = [ "error_message" , "param" ];
            //$i18nFiles = [ "marshall_debug"];

            $excel_text_array = array();


            //以英文當基準 建出 key鍵值的array物件
            \App::setLocale("en");                          
            foreach ($i18nFiles as $each_file) 
            {
                $all_text_array = \Lang::get($each_file);

                $excel_text_item = array();
                $excel_text_item["file_category"] = $each_file;

                foreach ($all_text_array as $key => $text) 
                {                    
                    $excel_text_item = array();
                    $excel_text_item["file_category"] = $each_file;       
                    $excel_text_item["lang_key"] = $key;           
                    $excel_text_item["sort_text"] =  $each_file."_".$key;    
                    $excel_text_item["en"] = "";           
                    $excel_text_item["zh-tw"] = "";    
                    array_push($excel_text_array ,$excel_text_item );
                }
            }


            usort($excel_text_array, function ($item1, $item2) {
                return $item1['sort_text'] <=> $item2['sort_text'];
            });

            foreach ($langs as $each_lang) 
            {
                foreach ($i18nFiles as $each_file) 
                {
                    \App::setLocale($each_lang);        
                    $all_text_this_lang = \Lang::get($each_file);

                    foreach ($excel_text_array as $item_key => $array_item) 
                    {                    
                        $file_category = $array_item["file_category"];

                        if($file_category != $each_file)
                            continue ;
                            
                        $lang_key = $array_item["lang_key"];

                        $isExist = array_key_exists($lang_key ,$all_text_this_lang);

                        if($isExist)
                        {                            
                            $excel_text_array[$item_key][$each_lang] = $all_text_this_lang[$lang_key];
                        }
                        else 
                        {                            
                            $excel_text_array[$item_key][$each_lang]  = "";
                        }
                    }                
                }               
            }
            
            $symbol_tab = "\t";
            $symbol_newline = "\n";

            $output_text = "";
            $output_all_text_line = array();

            foreach ($excel_text_array as $item) {         

                $output_this_line = "";
                $output_this_line = $output_text.$item["file_category"].$symbol_tab.$item["lang_key"].$symbol_tab;

                foreach ($langs as $each_lang){
                    $output_this_line =  $output_this_line.$item[$each_lang].$symbol_tab;                    
                }
                
                array_push($output_all_text_line ,  $output_this_line);
            }

            return implode($symbol_newline, $output_all_text_line);


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



    /*
        /data-convert/excel/to/php-lang
        讀取語系檔並轉成Excel格式
    */
      public function convert_excel_to_langResourceFormat(Request $request)
      {      
          try {
  
            $debug = $request->isCheck;
            $applicationType = $request->applicationType;
            $compareTargetLangKeys = explode("," , $request->compareTargetLangKeys);
            
            $excel_content = $request->excel_content;

            if(Common::IsNotNullOrEmptyString($excel_content))
            {
                $bodyContent = $excel_content;
            }
            else
            {
                $bodyContent = $request->getContent();
            }

            $each_lines = explode("\n",$bodyContent);

            $all_files = array();
            $all_lang_keys = array();

            foreach ($each_lines as $line) {

                $each_columns = explode("\t",$line);

                $file_name = $each_columns[0];
                array_push($all_files , $file_name);

            }

            $targetLangs = [ "en" , "zh-tw" , "zh-cn" , "vi-vn" ];
            $numberOfLangs = count($targetLangs);

            $all_files = array_unique($all_files);
            $all_files = array_values($all_files);

            $list_result = array();

            $totalLines = count($each_lines);
  
            foreach ($all_files as  $each_file) {

                $all_text_in_file = array();

                $loopTimes = 0;
                
                foreach ($each_lines as $line) {

                    $loopTimes++;
                    $each_excel_columns = explode("\t",$line);
                    
                    $file_name = $each_excel_columns[0];
                    $lang_key = $each_excel_columns[1];

                    if($file_name != $each_file) {
                        continue;
                    }

                    $all_text_lang_key = array();

                    //從第 [2] index開始，是多語系文字
                    for ($x = 2; $x < (2 + $numberOfLangs); $x++) {

                        $isKeyExist = array_key_exists($x , $each_excel_columns);

                        if($isKeyExist)
                        {
                            array_push($all_text_lang_key , $each_excel_columns[$x]);                           
                        }                       
                    }

                    $all_text_in_file[$lang_key] = $all_text_lang_key;
                    $debug = "";                
                }

                //字母排序
                ksort($all_text_in_file);
                $list_result[$each_file] = $all_text_in_file;
            }

            $file_outCode_result = array();
            $undefined_columns = array();  

            $change_line = "\n";

            $is_API_Agent = Common::IsNullOrEmptyString($applicationType) || $applicationType == 1;   
            $is_Web_AgentBackend = $applicationType == 51;
            
            
            foreach ($list_result as $file_name => $allText_in_each_file) {

                $file_outCode_result[$file_name] = array();

                $applicationType = $request->applicationType;                


                //如果有指定要檢查 API.Agent / Web.AgentBackend 語系檔
                if ($is_API_Agent || $is_Web_AgentBackend) {
                    
                    if ($is_API_Agent) 
                    {
                        $undefined_result = $this->check_undefined_columns($file_name , $allText_in_each_file);
                    }
                    else if($is_Web_AgentBackend)
                    {
                        $undefined_result = $this->check_undefined_columns_by_targetCompareArray($compareTargetLangKeys , $allText_in_each_file);
                    }
                    
                    if(count($undefined_result) > 0)
                    {
                        foreach ($undefined_result as $undefined_item) {

                            array_push($undefined_columns , [
                                'file_name' => $file_name,
                                'lang_key' =>  $undefined_item
                            ]);
                            
                        }                     
                    }
                }

                //依序印出每個語系的文字 (e.g.  index=0 是第一排英文   index=2 是第二排繁體中文...etc)
                for ($loopIndex = 0; $loopIndex < ($numberOfLangs); $loopIndex++) {
                    
                    $temp_text_of_lang = array();


                    $temp_loop_times = 0;
                    $max_loopTimes = count($allText_in_each_file);

                    foreach ($allText_in_each_file as $lang_key => $value) {

                        $targetText = $allText_in_each_file[$lang_key][$loopIndex];               

                        //取代奇怪的換行符號
                        $targetText = trim(preg_replace('/\s\s+/', ' ', $targetText));

                        //最後一筆的後面不加逗號
                        $lastComman = $temp_loop_times == ($max_loopTimes - 1) ? "" : ",";

                        $php_code = "'".$lang_key."' => '".trim(preg_replace('/\s\s+/', ' ', $targetText))."'".$lastComman;

                        array_push($temp_text_of_lang , $php_code);
                        $temp_loop_times++;
                    }                    

                    $dynamicText = $is_Web_AgentBackend ? "$"."lang =" : "return";
                    $output_php_code = "<?php ".$change_line.$dynamicText." [ ".$change_line."\t".implode($change_line."\t" , $temp_text_of_lang).$change_line."];";

                    array_push($file_outCode_result[$file_name] , $output_php_code);
                }          
            }
                
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => 
                [
                    'undefined_columns' => $undefined_columns,
                    'list_files' => $all_files,
                    'list_langs' => $targetLangs,
                    'file_outCode_result' => $file_outCode_result,
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

  
    /* 跟當前的多語系檔案比對 是否有excel未定義的多語系鍵值 */
    private function check_undefined_columns_by_targetCompareArray($compare_exist_langKeys_array , $parse_all_langKeys)
    {        
        $list_not_exist_key = array();


        $qqqq1 = count($compare_exist_langKeys_array);
        $qqqq2 = count($parse_all_langKeys);



        foreach ($compare_exist_langKeys_array as $value) {
            
            $isExist = array_key_exists($value, $parse_all_langKeys);

            if(!$isExist)
            {
                array_push($list_not_exist_key , $value);
            }
        }

        return $list_not_exist_key;
    }


    /* 跟當前的多語系檔案比對 是否有excel未定義的多語系鍵值 */
    private function check_undefined_columns($file_name , $parse_all_langKeys)
    {
        //以英文當基準來查 是否excel有漏哪些欄位
        \App::setLocale("en");          
        $all_text_array = \Lang::get($file_name);

        if(!is_array($all_text_array) || count($all_text_array) == 0)
        {
            return [];
        }

        $list_not_exist_key = array();

        foreach ($all_text_array as $key => $value) {
            
            $isExist = array_key_exists($key, $parse_all_langKeys);

            if(!$isExist)
            {
                array_push($list_not_exist_key , $key);
            }
        }

        return $list_not_exist_key;
    }

}