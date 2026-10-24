<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\Common;
use App\Models\AgentDomain;
use App\Models\Agent;
use DB;
use Hash;

class SynchronizeAgent extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'synchronize_agent';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '同步代理到客服帳號';

    /**
     * Create a new command instance.
     * 
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //查詢未綁定帳號
        $agents = DB::table('agents as a')
            ->join('agents as p', 'p.id', 'a.parent')
            ->where('a.cs_sync_flag', 1)
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
            )->offset(0)->limit(1000)
            ->get();
        foreach ($agents as $row) {
            $data = [
                'username' => $row->username,
                'password' => $row->password,
                'nickname' => $row->nickname,
                'code' => $row->code,
                'parent' => $row->parent,
                'domain' => $this->getAgentDomain($row),
                'chat_group' => $row->chat_group_id,
                'organization_id' => $row->organization_id
            ];
            $result = $this->post($data);
            if (is_array($result) && $result['code'] == 1000) {
                //更新api user及api key
                Agent::find($row->id)
                    ->update(
                        [
                            'cs_sync_flag' => 0,
                            'CS_API_USER' => $result['data']['API_USER'],
                            'CS_API_KEY' => $result['data']['API_KEY']
                        ]
                    );
            }
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

    private function post($data)
    {
        Common::log(0, $data, 'synchronize_agent');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, env('CS_API') . 'synchronize');
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $result = curl_exec($ch);

        curl_close($ch);
        Common::log(0, $result, 'synchronize_agent_result');
        return json_decode($result, true);
    }
}