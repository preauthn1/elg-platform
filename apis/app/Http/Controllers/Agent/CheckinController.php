<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Checkin\CheckinCreateRequest;
use App\Http\Requests\Agent\Checkin\CheckinDetailRequest;
use App\Http\Requests\Agent\Checkin\CheckinRecordRequest;
use App\Http\Requests\Agent\Checkin\CheckinUpdateRequest;
use App\Http\Requests\Agent\Checkin\CheckinUpdateStatusRequest;
use App\Http\Resources\Agent\Checkin\CheckinDetailResource;
use App\Http\Resources\Agent\Checkin\CheckinListResource;
use App\Http\Resources\Agent\Checkin\CheckinRecordResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Models\Agent;
use App\Models\CheckinActivity;
use App\Models\CheckinPrize;
use App\Models\CheckinRecord;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Facades\DB;

#[Group('checkin (打卡活動)')]
class CheckinController extends Controller
{
    public function list()
    {
        $agent = $this->getAgentContext()->agent;

        $data = CheckinActivity::select('checkin_activities.id', 'agents.username AS agent_name', 'checkin_activities.start_date', 'checkin_activities.end_date', 'checkin_activities.status')
            ->leftJoin('agents', 'agents.id', '=', 'checkin_activities.agent_id')
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
            ->get();

        return ApiResponse::success(CheckinListResource::collection($data));
    }

    public function detail(CheckinDetailRequest $request)
    {
        $data = CheckinActivity::select('id', 'agent_id', 'flow_limit_multiple', 'ip_block', 'phone_block', 'account_block', 'start_date', 'end_date')
            ->where('id', $request->id)
            ->first();

        $this->assertAgentInScope($data?->agent_id);

        return ApiResponse::success(CheckinDetailResource::make($data));
    }

    /**
     * 確認打卡活動所屬代理落在本代理可視範疇內；範疇比照讀取端 list()。
     *
     * 查無與越權都回同一個 9003，避免以回應差異枚舉他人代理線的活動 id。
     */
    private function assertAgentInScope(?int $agentId): void
    {
        if ($agentId === null || !$this->getAgentContext()->agent->canSeeAgent($agentId)) {
            throw new ApiException(__('error_message.9003')); // 權限不足
        }
    }

    public function record(CheckinRecordRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        $data = CheckinRecord::select('checkin_records.id', 'm.username', 'checkin_records.created_at')
            ->leftJoin('members as m', 'm.id', '=', 'checkin_records.member_id')
            ->with('checkinPrizeRecords.checkinPrize')
            ->when($request->username, function ($query) use ($request) {
                $query->where('m.username', 'like', '%' . $request->username . '%');
            })
            ->when($request->start_date && $request->end_date, function ($query) use ($request) {
                $startTime = Carbon::parseFrontend($request->start_date . ':00')->toSystemTime()->toDateTimeString();
                $endTime = Carbon::parseFrontend($request->end_date . ':59')->toSystemTime()->toDateTimeString();

                $query->whereBetween('checkin_records.created_at', [$startTime, $endTime]);
            })
            // 只有admin跟admin子帳號可以看全部
            ->when($agent->id != 1 && $agent->host_id != 1, function ($query) use ($agent) {
                $query->whereHas('checkinActivity', function ($subQuery) use ($agent) {
                    $hierarchy_path = $agent->hierarchy_path;
                    // 代理子帳號可以看到代理的活動
                    if ($agent->host_id != 0) {
                        $upperAgent = Agent::where('id', $agent->host_id)->first();
                        $hierarchy_path = $upperAgent->hierarchy_path;
                    }

                    $subQuery->leftJoin('agents', 'agents.id', '=', 'checkin_activities.agent_id')
                        ->where('agents.hierarchy_path', 'like', $hierarchy_path . '%');
                });
            })
            ->orderBy('created_at', 'DESC')
            ->paginate($request->per_page);

        return ApiResponse::successWithPagination(
            data: CheckinRecordResource::collection($data),
            paginator: OnlyPaginateResource::make($data)
        );
    }

    public function create(CheckinCreateRequest $request)
    {
        // agent_id 由請求指定，未限範疇時可把活動建在任意代理下
        $this->assertAgentInScope((int) $request->agent_id);

        if (CheckinActivity::where('agent_id', $request->agent_id)->where('status', 1)->exists()) {
            return ApiResponse::error(__('error_message.1987'));
        }

        return DB::transaction(function () use ($request) {
            $checkinActivity = CheckinActivity::create([
                'agent_id' => $request->agent_id,
                'flow_limit_multiple' => $request->flow_limit_multiple,
                'ip_block' => $request->ip_block,
                'phone_block' => $request->phone_block,
                'account_block' => $request->account_block,
                'start_date' => Carbon::parseFrontend($request->start_date)->toSystemTime()->toDateTimeString(),
                'end_date' => Carbon::parseFrontend($request->end_date)->endOfMinute()->toSystemTime()->toDateTimeString(),
            ]);

            for ($i = 0; $i < count($request->day); $i++) {
                CheckinPrize::create([
                    'checkin_activity_id' => $checkinActivity->id,
                    'day' => $request->day[$i],
                    'prize_type' => $request->prize_type[$i],
                    'prize_amount' => $request->prize_amount[$i],
                ]);
            }

            return ApiResponse::success();
        });
    }

    public function update(CheckinUpdateRequest $request)
    {
        $checkinActivity = CheckinActivity::find($request->id);

        // 活動本身與要改掛的目標代理都必須在範疇內，否則可把任意活動搬到任意代理下
        $this->assertAgentInScope($checkinActivity?->agent_id);
        $this->assertAgentInScope((int) $request->agent_id);

        // 活動如果已經開始不能修改
        if ($checkinActivity->start_date < date('Y-m-d')) {
            return ApiResponse::error(__('error_message.1988'));
        }

        return DB::transaction(function () use ($request, $checkinActivity) {
            $checkinActivity->update([
                'agent_id' => $request->agent_id,
                'flow_limit_multiple' => $request->flow_limit_multiple,
                'ip_block' => $request->ip_block,
                'phone_block' => $request->phone_block,
                'account_block' => $request->account_block,
                'start_date' => Carbon::parseFrontend($request->start_date)->toSystemTime()->toDateTimeString(),
                'end_date' => Carbon::parseFrontend($request->end_date)->endOfMinute()->toSystemTime()->toDateTimeString(),
            ]);

            CheckinPrize::where('checkin_activity_id', $request->id)->delete();
            for ($i = 0; $i < count($request->day); $i++) {
                CheckinPrize::create([
                    'checkin_activity_id' => $checkinActivity->id,
                    'day' => $request->day[$i],
                    'prize_type' => $request->prize_type[$i],
                    'prize_amount' => $request->prize_amount[$i],
                ]);
            }

            return ApiResponse::success();
        });
    }

    public function updateStatus(CheckinUpdateStatusRequest $request)
    {
        $checkinActivity = CheckinActivity::where('id', $request->id)->first();

        $this->assertAgentInScope($checkinActivity?->agent_id);

        if ($request->status == 1) {
            if (CheckinActivity::where('agent_id', $checkinActivity->agent_id)->where('status', 1)->exists()) {
                return ApiResponse::error(__('error_message.1987'));
            }
        }

        $checkinActivity->update([
            'status' => $request->status,
        ]);

        return ApiResponse::success();
    }
}
