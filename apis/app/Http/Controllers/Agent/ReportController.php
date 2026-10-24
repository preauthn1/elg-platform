<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Report\Sr12Request;
use App\Http\Resources\Agent\Report\SR12PaginateResource;
use App\Http\Resources\Agent\Report\SR12Resource;
use App\Http\Resources\Agent\Report\SR12SummaryResource;
use App\Http\Resources\PaginateResource;
use App\Models\Agent;
use App\Models\Member;
use App\Models\TotalBetRecord;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @knowledge rules/report-sr12-payout
 */
#[Group('報表')]
class ReportController extends Controller
{
    public function sr12(Sr12Request $request)
    {
        $agentData = $this->getAgentContext();
        $agent = $agentData->agent;
        $hostId = $agent->host_id == 0 ? $agent->id : $agent->host_id;
        $languageId = $agentData->languageId;

        // 參數處理
        $input = $request->validated();
        $startTime = Carbon::parse($input['start_time'])->toSystemTime()->format('Y-m-d H:i:s');
        $endTime = Carbon::parse($input['end_time'])->toSystemTime()->format('Y-m-d H:i:s');
        $agentUsername = $input['agent_username'] ?? null;
        $memberUsername = $input['member_username'] ?? null;
        $whichTime = $input['which_time'] ?? 1; // 1:押注時間 2:結算時間
        $gameType = $input['game_type'] ?? 0; // 遊戲類型 @see App\Enums\Game\GameTypeEnum
        $gameNo = $input['game_no'] ?? null; // 三方注單號
        $sortBy = $input['sort_by'] ?? 1; // 1:投注時間 2:投注金額
        $page = $input['page'] ?? 1;
        $perPage = $input['per_page'] ?? 10;

        $targetAgent = $agentUsername ? Agent::where('username', $agentUsername)->first() : null;
        if ($agentUsername && !$targetAgent) {
            return ApiResponse::error('查無此代理');
        }
        $targetMember = $memberUsername ? Member::where('username', $memberUsername)->first() : null;
        if ($memberUsername && !$targetMember) {
            return ApiResponse::error('查無此會員');
        }

        $query = TotalBetRecord::query()
            ->where('vendor_id', $input['vendor_id'])
            ->when($whichTime, function ($query) use ($whichTime, $startTime, $endTime) {
                $timeField = $whichTime == 2 ? 'payout_time' : 'bet_time';
                $query->whereBetween($timeField, [$startTime, $endTime]);
            })
            ->when($gameType, function ($query) use ($gameType) {
                $query->whereHas('game', function ($subQuery) use ($gameType) {
                    $subQuery->where('type_id', $gameType);
                });
            })
            ->when($gameNo, function ($query) use ($gameNo) {
                $query->where('reference_no', $gameNo);
            })
            ->when($targetAgent, function ($query) use ($targetAgent) {
                $query->whereHas('member.agent', function ($subQuery) use ($targetAgent) {
                    $subQuery->where('username', $targetAgent->username);
                });
            })
            ->when($targetMember, function ($query) use ($targetMember) {
                $query->whereHas('member', function ($subQuery) use ($targetMember) {
                    $subQuery->where('username', $targetMember->username);
                });
            })
            ->when(!$targetAgent && !$targetMember, function ($query) use ($hostId) {
                $query->whereHas('member.agent', function ($subQuery) use ($hostId) {
                    $subQuery->where('id', $hostId);
                });
            });

        // 聚合統計
        $summaryData = (clone $query)->select(
            DB::raw('sum(bet) as bet_amount'),
            DB::raw('sum(valid_bet) as valid_bet_amount'),
            // 派彩 = 押注 + 輸贏，與 SR14 一致（target/tickets#4445）
            DB::raw('sum(bet + win_lose) as payout'),
            DB::raw('sum(win_lose) as win_lose'),
            DB::raw('sum(rolling_chip) as rolling_chip'),
            DB::raw('sum(commission) as commission'),
            DB::raw('count(*) as cnt')
        )->first();

        // 分頁查詢
        $sortField = $sortBy == 2 ? 'bet' : 'bet_time';
        $data = $query->with([
            'member' => function ($query) {
                $query->select(['id', 'parent', 'username']);
            },
            'member.agent' => function ($query) {
                $query->select(['id', 'username']);
            },
            'vendor' => function ($query) {
                $query->select(['id', 'name']);
            },
            'vendor.vendorCustomizedItems' => function ($query) use ($languageId, $agent) {
                $query->where('item_type', $languageId);
                $query->where('status', 1);
                $query->where('agent_group_id', $agent->vendor_customize_group_id);
                $query->select('id', 'vendor_id', 'name', 'status');
            },
            'game' => function ($query) {
                $query->select(['id', 'name']);
            },
            'game.gameNames' => function ($query) use ($languageId) {
                $query->where('language_id', $languageId);
                $query->select('id', 'game_id', 'name');
            },
        ])->orderByDesc($sortField)
            ->orderByDesc('id')
            ->paginate($perPage);

        $pagination = [
            'total' => $data->total(),
            'per_page' => $data->perPage(),
            'current_page' => $data->currentPage(),
            'last_page' => $data->lastPage(),
        ];

        return new SR12PaginateResource(
            SR12Resource::collection($data->items()),
            SR12SummaryResource::make($summaryData),
            $pagination
        );

        // return PaginateResource::make(SR12Resource::collection($data));

        // return ApiResponse::success([
        //     'summary' => $summaryData ? $summaryData->toArray() : [],
        //     'items' => $data->items(),
        //     'pagination' => [
        //         'total' => $data->total(),
        //         'per_page' => $data->perPage(),
        //         'current_page' => $data->currentPage(),
        //         'last_page' => $data->lastPage(),
        //     ],
        // ]);
    }
}
