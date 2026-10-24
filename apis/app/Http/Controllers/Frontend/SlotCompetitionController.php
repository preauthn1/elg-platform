<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\SlotCompetition\EventIdRequest;
use App\Http\Requests\Frontend\SlotCompetition\LeaderBoardRequest;
use App\Http\Requests\Frontend\SlotCompetition\ReceiveRequest;
use App\Services\SlotCompetitionService;
use Dedoc\Scramble\Attributes\Group;

#[Group('Slot Competition (老虎機爭霸賽)')]
class SlotCompetitionController extends Controller
{
    public function __construct(
        protected SlotCompetitionService $slotCompetitionService,
    ) {}

    /**
     * 期數列表
     *
     * OLD: /api/event/slot_competition/list
     */
    public function list()
    {
        $userData = $this->getUserContext();
        $agent = $userData->member->agent ?? $userData->agent;

        return ApiResponse::success($this->slotCompetitionService->getIssueList($agent));
    }

    /**
     * 排行榜
     *
     * - 自己未在名次內時，於列表尾端補上自己的紀錄，名次顯示 `--`
     *
     * OLD: /api/event/slot_competition/leader_board
     */
    public function leaderBoard(LeaderBoardRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();

        $data = $this->slotCompetitionService->getLeaderBoard(
            member: $userData->member,
            agent: $userData->member->agent ?? $userData->agent,
            eventId: (int) $input['event_id'],
            no: (int) $input['no'],
            page: isset($input['page']) ? (int) $input['page'] : null,
            perPage: isset($input['per_page']) ? (int) $input['per_page'] : null,
        );

        return ApiResponse::success($data);
    }

    /**
     * 我的排名
     *
     * OLD: /api/event/slot_competition/ranking
     */
    public function ranking(EventIdRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();

        return ApiResponse::success(
            $this->slotCompetitionService->getMyRanking(
                $userData->member,
                $userData->member->agent ?? $userData->agent,
                (int) $input['event_id'],
            )
        );
    }

    /**
     * 獎項列表
     *
     * OLD: /api/event/slot_competition/awards
     */
    public function awards(EventIdRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();

        return ApiResponse::success(
            $this->slotCompetitionService->getAwards(
                $userData->member->agent ?? $userData->agent,
                (int) $input['event_id'],
                $userData->languageId,
            )
        );
    }

    /**
     * 領取獎項
     *
     * OLD: /api/event/slot_competition/receive
     */
    public function receive(ReceiveRequest $request)
    {
        $input = $request->validated();

        $userData = $this->getUserContext();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $this->slotCompetitionService->receive($member, $agent, (int) $input['event_id'], (int) $input['no']);

        return ApiResponse::success();
    }
}
