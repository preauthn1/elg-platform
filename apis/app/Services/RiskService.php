<?php

declare(strict_types=1);

namespace App\Services;

use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentLoginIpLog;
use App\Models\MemberLoginIpLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RiskService
{
    /**
     * 檢查代理登入 IP 是否有風險
     */
    public function detectIpRisk(Agent $agent): void
    {
        $clientIp = Common::getIp();
        $ipParts = $this->splitIp($clientIp);
        $this->logAgentIp($agent->id, $ipParts);

        // 只檢查特定代理層級
        $hierarchyPath = '/1/33893/66829%';
        $searchTime = now()->subDays(7)->toDateTimeString();

        $agentIpLogs = $this->getAgentIpLogs($ipParts, $hierarchyPath, $searchTime);
        $memberIpLogs = $this->getMemberIpLogs($ipParts, $hierarchyPath, $searchTime);

        if (count($agentIpLogs) > 1 || count($memberIpLogs) > 0) {
            $exceptIps = ['0.0.0.0', '0.0.0.0', '0.0.0.0'];
            if (!in_array($clientIp, $exceptIps)) {
                $this->notifyIpRisk($clientIp, $agentIpLogs, $memberIpLogs);
            }
        }
    }

    private function splitIp(string $ip): array
    {
        return explode('.', $ip);
    }

    private function logAgentIp(int $agentId, array $ipParts): void
    {
        AgentLoginIpLog::create([
            'agent_id' => $agentId,
            'ip_1' => $ipParts[0] ?? '',
            'ip_2' => $ipParts[1] ?? '',
            'ip_3' => $ipParts[2] ?? '',
            'ip_4' => $ipParts[3] ?? '',
            'admin' => $agentId,
        ]);
    }

    private function getAgentIpLogs(array $ipParts, string $hierarchyPath, string $searchTime): Collection
    {
        return AgentLoginIpLog::query()
            ->from('agent_login_ip_logs')
            ->join('agents', function ($join) use ($hierarchyPath) {
                $join->on('agents.id', '=', 'agent_login_ip_logs.agent_id')
                    ->where('agents.hierarchy_path', 'like', $hierarchyPath . '%');
            })
            ->where('agent_login_ip_logs.ip_1', $ipParts[0] ?? '')
            ->where('agent_login_ip_logs.ip_2', $ipParts[1] ?? '')
            ->where('agent_login_ip_logs.ip_3', $ipParts[2] ?? '')
            ->where('agent_login_ip_logs.ip_4', $ipParts[3] ?? '')
            ->where('agent_login_ip_logs.created_at', '>=', $searchTime)
            ->groupBy('agents.username')
            ->orderBy(DB::raw('MAX(agent_login_ip_logs.created_at)'), 'DESC')
            ->select([
                'agents.username as name',
                DB::raw('MAX(DATE_ADD(agent_login_ip_logs.created_at, INTERVAL 8 HOUR)) as created_at'),
            ])
            ->get();
    }

    private function getMemberIpLogs(array $ipParts, string $hierarchyPath, string $searchTime): Collection
    {
        return MemberLoginIpLog::query()
            ->from('member_login_ip_logs')
            ->join('members', 'members.id', '=', 'member_login_ip_logs.member_id')
            ->join('agents', function ($join) use ($hierarchyPath) {
                $join->on('agents.id', '=', 'members.parent')
                    ->where('agents.hierarchy_path', 'like', $hierarchyPath . '%');
            })
            ->where('member_login_ip_logs.ip_1', $ipParts[0] ?? '')
            ->where('member_login_ip_logs.ip_2', $ipParts[1] ?? '')
            ->where('member_login_ip_logs.ip_3', $ipParts[2] ?? '')
            ->where('member_login_ip_logs.ip_4', $ipParts[3] ?? '')
            ->where('member_login_ip_logs.created_at', '>=', $searchTime)
            ->groupBy('members.username')
            ->orderBy(DB::raw('MAX(member_login_ip_logs.created_at)'), 'DESC')
            ->select([
                'members.username as name',
                DB::raw('MAX(DATE_ADD(member_login_ip_logs.created_at, INTERVAL 8 HOUR)) as created_at'),
            ])
            ->get();
    }

    private function notifyIpRisk(string $ip, $agentIpLogs, $memberIpLogs): void
    {
        $lines = ['IP: ' . $ip];
        foreach ($agentIpLogs as $log) {
            $lines[] = "{$log->created_at} 代理: {$log->name} 登入";
        }
        foreach ($memberIpLogs as $log) {
            $lines[] = "{$log->created_at} 會員: {$log->name} 登入";
        }
        BotNotify::channel('warn')->send(implode(PHP_EOL, $lines));
    }
}
