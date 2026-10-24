<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Mission\ClaimRecordRequest;
use App\Http\Resources\Agent\Mission\ClaimRecordResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Models\Agent;
use App\Models\MissionClaimRecord;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;

#[Group('mission (每日任務活動)')]
class MissionController extends Controller
{
    public function claimRecord(ClaimRecordRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $data = MissionClaimRecord::select(['mission_claim_records.*', 'members.username as member_username', 'agents.username as agent_username', 'missions.name as mission_name', 'example.invalid as mission_info'])
            ->leftJoin('members', 'members.id', '=', 'mission_claim_records.member_id')
            ->leftJoin('missions', 'missions.id', '=', 'mission_claim_records.mission_id')
            ->leftJoin('mission_available_agents', 'mission_available_agents.mission_id', '=', 'missions.id')
            ->leftJoin('agents', 'agents.id', '=', 'members.parent')
            // 只有admin跟admin子帳號可以看全部
            ->when($agent->id != 1 && $agent->host_id != 1, function ($query) use ($agent) {
                $hierarchy_path = $agent->hierarchy_path;
                // 代理子帳號可以看到代理的活動
                if ($agent->host_id != 0) {
                    $upperAgent = Agent::where('id', $agent->host_id)->first();
                    $hierarchy_path = $upperAgent->hierarchy_path;
                }

                $query->where('agents.hierarchy_path', 'like', $hierarchy_path . '%');
            })
            ->when($request->member_username, function ($query) use ($request) {
                $query->where('members.username', $request->member_username);
            })
            ->when($request->reward_type, function ($query) use ($request) {
                $query->where('mission_claim_records.reward_type', $request->reward_type);
            })
            ->when($request->is_claimed == 0 || $request->is_claimed == 1, function ($query) use ($request) {
                $query->where('mission_claim_records.is_claimed', $request->is_claimed);
            })
            ->when($request->start_time && $request->end_time, function ($query) use ($request) {
                $startTime = Carbon::parseFrontend($request->start_time . ':00')->toSystemTime()->toDateTimeString();
                $endTime = Carbon::parseFrontend($request->end_time . ':59')->toSystemTime()->toDateTimeString();
                $timeColumn = $request->time_column ?? 'claim_time';

                $query->whereBetween('mission_claim_records.' . $timeColumn, [$startTime, $endTime]);
            })
            ->orderBy('mission_claim_records.created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return ApiResponse::successWithPagination(
            data: ClaimRecordResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }
}
