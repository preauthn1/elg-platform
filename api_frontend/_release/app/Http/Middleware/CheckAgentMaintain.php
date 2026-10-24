<?php

namespace App\Http\Middleware;

use Illuminate\Support\Facades\Schema;
use App\Helpers\Common;
use Closure;
use DB;
use App\Models\Language;
use App;
use App\Models\MemberAccessToken;
use App\Models\Member;
use App\AES;
use App\REDIS;
use App\Repositories\AgentRepository;
use App\Repositories\AgentPersonalSettingRepository;
use App\Repositories\MemberAccessTokenRepository;


class CheckAgentMaintain
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        try {           

            $redis = new REDIS();

            $redis_key_maintain_agents = array('maintain_agents_v1');

            $maintainAgents = [];

            //取得設置為維護中的代理IDs (redis/查DB)
            if ($redis->exists($redis_key_maintain_agents)) 
            {          
                $maintainAgents = $redis->get($redis_key_maintain_agents);

                $utcNow = gmdate("Y-m-d H:i:s");

                //從cache抓的時間可能已超過要再檢查一次
                $maintainAgents = array_filter($maintainAgents , function ($var) use ($utcNow) {
                    return ($var["start_time"] < $utcNow);
                });

                $maintainAgents = array_filter($maintainAgents , function ($var) use ($utcNow) {
                    return ($var['end_time'] > $utcNow);
                });                
            }
            else
            {
                $settingRep = new AgentPersonalSettingRepository();     
                $queryMaintainAgents =  $settingRep->getMaintainAgents();  

                //強制將stdClass轉成array格式
                $maintainAgents = json_decode(json_encode($queryMaintainAgents) , true);
                $redis->set($redis_key_maintain_agents , $maintainAgents);
            }

            //若array全空、無人設置 結束邏輯
            if(count($maintainAgents) <= 0)
            {
                return $next($request);
            }

            //取得自己樹狀結構的代理IDs (e.g  [ '4284' , '4280' , '1'] )            
            $my_agent_id = $request->MY_PARENT_AGENT;   
            $myHierarchyAgentIDs = $this->getMyHierarchyAgentIDs($my_agent_id);
            
            $isAnyMatch = false;

            foreach($myHierarchyAgentIDs as $eachAgentID)
            {
                foreach($maintainAgents as $matainAgents)
                {
                    if($matainAgents["agent_id"]  == $eachAgentID)
                    {
                        $isAnyMatch = true;
                    }
                }
            }

            //若自己所屬代理設置為維護中，強制登出
            if($isAnyMatch)
            {
                $repOfToken = [REDACTED] MemberAccessTokenRepository();
                $repOfToken->DoLogoutProcess($request->TOKEN);

                return response()->json([
                    'code' => 9998,
                    'message' => __('error_message.9998')
                ]);
            }

            return $next($request);

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


    private function getMyHierarchyAgentIDs($my_agent_id)
    {   
        $redis = new REDIS();                 

        $redis_key = array('my_hierarchy_agent_ids' ,  $my_agent_id);

        $myAgentIDs = [];

        if ($redis->exists($redis_key)) 
        {
            $myAgentIDs = $redis->get($redis_key);
        }
        else
        {
            $rep = new AgentRepository();
            $myAgentIDs = $rep->getHierarchyAgentIDsArray($my_agent_id);
            $redis->set($redis_key , $myAgentIDs );
        }

        return $myAgentIDs;
    }




}