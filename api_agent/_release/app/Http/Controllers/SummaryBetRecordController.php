<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Advertisement;
use App\Models\AdvertisementPath;
use App\REDIS;
use App\Helpers\ArrayHelper;
use App\Helpers\Common;
use App\Helpers\SQLHelper;
use Validator;
use DB;

class SummaryBetRecordController extends Controller
{

    /**
     * record/compare/summary-vs-total
     * - 排除問題使用，檢查summary跟totalBet
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": 
     * }
     */
    public function compare_summaryBet_vs_totalBet(Request $request)
    {
        
        try {     
                $vendor_id = $request->vendor_id;
                $username = $request->username;
                $start_time = $request->start_time;
                $end_time = $request->end_time;

                // $vendor_id = 25;                
                // $username = "Oncel9999";
                // $start_time = "2021-03-16";
                // $end_time = "2021-03-19";

                if(Common::IsNullOrEmptyString($start_time) || Common::IsNullOrEmptyString($end_time))
                {
                    return response()->json([
                        'code' => 9997,
                        'message' => '參數不合法',
                        'data' => ''
                    ]);
                }
                
                $allDates = $this->getDatesFromRange($start_time ,$end_time);

                $queryMember = DB::table("members as m")
                    ->join("agents as a" , "m.parent" , "a.id")
                    ->where("m.username" , $username)
                    ->select(
                        "m.id as member_id" , 
                        'm.username as member_username',  
                        "a.id as agent_id" , 
                        'a.username as agent_username' , 
                        'a.hierarchy' , 
                        'a.hierarchy_path',
                        'a.customized_setting_group_id'
                        )
                    ->first();        

                if(!is_object($queryMember))
                {
                    return response()->json([
                        'code' => 4044,
                        'message' => __('error_message.4044'),
                        'data' => ''
                    ]);
                }

                $member_id = $queryMember->member_id;
                $hierarchy_agents = explode("/", $queryMember->hierarchy_path);
                $hierarchy_agents = array_diff($hierarchy_agents, [ "" ]);     

                $queryVendorInfo = DB::table("agent_vendors as av")
                    ->whereIn("agent_id" , $hierarchy_agents)
                    ->where("vendor_id" , $vendor_id)
                    ->orderBy("agent_id" , 'desc')
                    ->select("vendor_info_id")
                    ->first();        
                    
                if(!is_object($queryVendorInfo))
                {
                    return response()->json([
                        'code' => 4044,
                        'message' => '查無vendor_info',
                        'data' => ''
                    ]);
                }
                    
                $vendor_info_id = $queryVendorInfo->vendor_info_id;

                $query_summaryBet = DB::table("summary_bet_records")
                    ->where("member_id" , $member_id)
                    ->where("vendor_id" , $vendor_id)
                    ->where("summary_time" ,  ">=", $start_time)
                    ->where("summary_time" ,  "<", $end_time)
                    ->groupBy(DB::raw('DATE(summary_time)'))
                    ->select(DB::raw('DATE(summary_time) as summary_time') ,DB::raw('sum(bet) as bet') , DB::raw('sum(win_lose) as win_lose'))
                    ->get();        
                    
                $query_totalBet = DB::table("total_bet_records")
                    ->where("member_id" , $member_id)
                    ->where("vendor_id" , $vendor_id)
                    ->where("summary_time" ,  ">=", $start_time)
                    ->where("summary_time" ,  "<", $end_time)
                    ->groupBy(DB::raw('DATE(summary_time)'))
                    ->select(DB::raw('DATE(summary_time) as summary_time') ,DB::raw('sum(bet) as bet') , DB::raw('sum(win_lose) as win_lose'))
                    ->get();    
                        
                $query_gameBet = DB::table("total_bet_records")
                    ->where("member_id" , $member_id)
                    ->where("vendor_id" , $vendor_id)
                    ->where("summary_time" ,  ">=", $start_time)
                    ->where("summary_time" ,  "<", $end_time)
                    ->groupBy(DB::raw('DATE(summary_time)'))
                    ->select(DB::raw('DATE(summary_time) as summary_time') ,DB::raw('sum(bet) as bet') , DB::raw('sum(win_lose) as win_lose'))
                    ->get();     
                
                // DB::enableQueryLog(); 

                $sub = DB::table("total_bet_records")
                    ->where("member_id" , $member_id)
                    ->where("vendor_id" , $vendor_id)
                    ->where("summary_time" ,  ">=", $start_time)
                    ->where("summary_time" ,  "<", $end_time)
                    ->groupBy('game_id')
                    ->select('game_id' ,DB::raw('sum(bet) as bet') , DB::raw('sum(win_lose) as win_lose') ,DB::raw('count(bet) as total_count') );                   


                $queryBetGroupByGames = DB::table(DB::raw("({$sub->toSql()}) as sub"))
                    ->mergeBindings($sub) 
                    ->leftJoin("games as g" , "sub.game_id" , 'g.id')
                    ->select(
                        "sub.game_id" , 
                        "sub.bet" , 
                        'sub.win_lose' ,  
                        'sub.total_count' ,  
                        'g.name' ,               
                    )
                    ->get();    

                $gameIDs = ArrayHelper::StdClass_ToSingleColumnArray($queryBetGroupByGames , 'game_id');
                
                $query_vendorInfoGames = DB::table("vendor_info_games as vg")
                    ->whereIn("vg.game_id" , $gameIDs)
                    ->where("vg.vendor_info_id" , $vendor_info_id)
                    ->select("vg.game_id" , 'vg.join_bet_event as join_bet_event' , 'vg.valid_bet_rate as valid_bet_rate')
                    ->get();
    
                
                // $qqq = SQLHelper::ConvertSQLLogToSQLBindingCommand(DB::getQueryLog()[0]);

                $resultArray = array();
                $eachGameSummary = array();
                
                $arrayObject_summaryBet = json_decode(json_encode($query_summaryBet) , true);
                $arrayObject_totalBet = json_decode(json_encode($query_totalBet) , true);

                foreach( $allDates  as $date)
                {
                    $matchSummaryBet = ArrayHelper::Array_FindItemByColumnValue($arrayObject_summaryBet , 'summary_time' ,  $date);
                    $matchTotalBet = ArrayHelper::Array_FindItemByColumnValue($arrayObject_totalBet , 'summary_time' ,  $date);

                    $instance = array();
                    $instance["date"] = $date;
                    $instance["betAmount_totalBet"] = 0;
                    $instance["betAmount_summaryBet"] = 0;
                    $instance["winlose_totalBet"] = 0;
                    $instance["winlose_summaryBet"] = 0;

                    if(!is_null($matchSummaryBet))
                    {
                        $instance["betAmount_summaryBet"] = $matchSummaryBet["bet"];
                        $instance["winlose_summaryBet"] = $matchSummaryBet["win_lose"];
                    }

                    if(!is_null($matchTotalBet))
                    {
                        $instance["betAmount_totalBet"] = $matchTotalBet["bet"];
                        $instance["winlose_totalBet"] = $matchTotalBet["win_lose"];
                    }       
                    
                    $instance["isBetAmountEqual"] = $instance["betAmount_summaryBet"] == $instance["betAmount_totalBet"];
                    $instance["isWinLoseEqual"] = $instance["winlose_summaryBet"] == $instance["winlose_totalBet"];

                    array_push($resultArray , $instance);
                }
        


                $arrayObjectVIG = json_decode(json_encode($query_vendorInfoGames) , true); 

                foreach( $queryBetGroupByGames  as $each_game)
                {
                    $matchVendorInfoGame = ArrayHelper::Array_FindItemByColumnValue($arrayObjectVIG , 'game_id' ,  $each_game->game_id);

                    $myItem = array();
                    $myItem["game_id"] = $each_game->game_id;
                    $myItem["game_name"] = $each_game->name;
                    $myItem["bet"] = $each_game->bet;
                    $myItem["win_lose"] = $each_game->win_lose;
                    $myItem["total_count"] = $each_game->total_count;
                    $myItem["valid_bet_rate"] = 0;                
                    $myItem["is_join_bet_event"] = 0;
                    $myItem["is_setting_exist"] = 0;

                    if(!is_null($matchVendorInfoGame))
                    {
                        $myItem["is_join_bet_event"] = $matchVendorInfoGame["join_bet_event"];
                        $myItem["valid_bet_rate"] = $matchVendorInfoGame["valid_bet_rate"];
                        $myItem["is_setting_exist"] = 1;
                    }

                    array_push($eachGameSummary , $myItem);
                }
        

                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => 
                    [
                        'member_username' => $queryMember->member_username,
                        'member_id' => $queryMember->member_id,
                        'agent_id' => $queryMember->agent_id,
                        'agent_username' => $queryMember->agent_username,
                        'hierarchy_path' => $queryMember->hierarchy_path,
                        'customized_setting_group_id' => $queryMember->customized_setting_group_id,
                        'vendor_info_id' => $vendor_info_id,
                        'dateSummary' => $resultArray,
                        'gameSummary' => $eachGameSummary,
                        'allDates' => $allDates 
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

    private function getDatesFromRange($start, $end){

        $d = new \DateTime($start);
        $start = date_format($d, 'Y-m-d');

        $dates = array($start);

        while(end($dates) < $end){
            $dates[] = date('Y-m-d', strtotime(end($dates).' +1 day'));
        }
        
        return $dates;
    }


    private function messages()
    {
        return [
            'id.required' => __('error_message.1126'),
            'id.digits_between' => __('error_message.1127'),

            'path_id.required' => __('error_message.1103'),
            'path_id.digits_between' => __('error_message.1104'),

            'language_id.required' => __('error_message.2019'),
            'language_id.digits_between' => __('error_message.2020'),

            'country_id.digits_between' => __('error_message.2022'),

            'agent_id.digits_between' => __('error_message.2018'),

            'image.base64image' => __('error_message.2023'),
            'image_pc.base64image' => __('error_message.2023'),

            'url.url' => __('error_message.1110'),
            'url.between' => __('error_message.1123'),

            'game_id.digits_between' => __('error_message.1124'),

            'game_type_id.digits_between' => __('error_message.1410'),

            'vendor_id.digits_between' => __('error_message.1401'),

            'sort.digits_between' => __('error_message.1125'),

            'start_time.required' => __('error_message.1105'),
            'start_time.date_format' => __('error_message.1106'),

            'end_time.required' => __('error_message.1107'),
            'end_time.date_format' => __('error_message.1108'),
            'end_time.after' => __('error_message.1109'),

            'status.boolean' => __('error_message.1104')
        ];
    }
}