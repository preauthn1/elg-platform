<?php

namespace App\Services;
use App\Models\Agent;
use App\Helpers\Common;
use DB;

/*
跟客服DB 資料sync有關的行為
 */
class CSDataSyncService
{
    public function SyncCustomServiceDbData($agent_id)
    {
        $queryAgent = Agent::from('agents as a')
            ->leftJoin('agents as p', 'p.id', 'a.parent')
            ->where('a.id' , $agent_id)
            ->whereNotNull('p.id')
            ->select(
                'a.id',
                'a.username',
                'a.password',
                'a.nickname',
                'a.code',
                'p.username as parent',
                'a.hierarchy',
                'a.chat_group_id',
                'a.organization_id'
            )
            ->first();
        if(!is_object($queryAgent)) {
            return;
        }

        $debug = $queryAgent->password;

        try {       
            $data = [
                'username' => $queryAgent->username,
                'password' => $queryAgent->password,
                'nickname' => $queryAgent->nickname,
                'code' => $queryAgent->code,
                'parent' =>  $queryAgent->parent,
                'domain' => $this->getAgentDomain($queryAgent),
                'chat_group' => $queryAgent->chat_group_id,
                'organization_id' => $queryAgent->organization_id
            ];
           
            $result = Common::postToSocketWihtoutEncrpt('synchronize', $data);

            if (is_array($result) && $result['code'] == 1000) {
                $is_update = $result['data']['is_update'];

                //如果是新增才需要更新這邊的資料
                if($is_update != 1) {
                    //更新api user及api key
                    Agent::find($agent_id)
                        ->update(
                            [
                                'cs_sync_flag' => 0,
                                'CS_API_USER' => $result['data']['API_USER'],
                                'CS_API_KEY' => $result['data']['API_KEY']
                            ]
                        );
                }
            }

            return;
        } 
        catch (Exception $e) 
        {
            $msg = "";
        }  
    }

    private function getAgentDomain($agent) {
        // 查代理顯示的遊戲商,從自己開始然後往上線查找，如果自己沒設定就繼承上線的域名
        // 頭尾兩個會是無效的
        $array = explode(',', $agent->hierarchy);
        foreach($array as $agent_id) {
            $agentDomain = DB::table('agent_domains')
                ->where('agent_id', $agent_id)
                ->orderBy('sort', 'DESC')
                ->orderBy('id', 'ASC')
                ->first();
            if ($agentDomain) {
                return $agentDomain->domain;
            }
        }
    }
}