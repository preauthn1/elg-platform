<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\EventLuckyDraw\IssueDetailRequest;
use App\Http\Requests\Frontend\EventLuckyDraw\IssueTicketsRequest;
use App\Http\Requests\Frontend\EventLuckyDraw\ReceiveAllRequest;
use App\Http\Requests\Frontend\EventLuckyDraw\ReceiveRequest;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\LuckyDrawService;
use Dedoc\Scramble\Attributes\Group;

#[Group('EventLuckyDraw (抽獎券活動)')]
class EventLuckyDrawController extends Controller
{
    public function __construct(
        private LuckyDrawService $luckyDrawService,
    ) {}

    /**
     * 抽獎活動期數列表
     *
     * OLD: /api/event/lucky-draw/issues
     */
    public function issueList()
    {
        $userData = $this->getUserContext();

        $list = $this->luckyDrawService->getIssueList($userData->agent);

        return ApiResponse::success($list);
    }

    /**
     * 抽獎活動明細
     *
     * OLD: /api/event/lucky-draw/issues/detail
     */
    public function issueDetail(IssueDetailRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();

        $data = $this->luckyDrawService->getIssueDetail((int) $input['issue_id'], $userData->member->id);

        return ApiResponse::success($data);
    }

    /**
     * 每期抽獎券明細
     *
     * OLD: /api/event/lucky-draw/issues/tickets
     */
    public function issueTickets(IssueTicketsRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();

        $result = $this->luckyDrawService->getIssueTickets(
            (int) $input['issue_id'],
            $userData->member->id,
            $userData->languageId,
            (int) $input['per_page'],
            (int) $input['page'],
        );

        return ApiResponse::success([
            'result_number' => $result['result_number'],
            'items' => $result['paginator']->items(),
            'pagination' => new OnlyPaginateResource($result['paginator']),
        ]);
    }

    /**
     * 領取獎項
     *
     * OLD: /api/event/lucky-draw/issues/receive
     */
    public function receive(ReceiveRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();

        $this->luckyDrawService->receive($userData->member, (int) $input['ticket_id']);

        return ApiResponse::success();
    }

    /**
     * 該期獎項全部領取
     *
     * OLD: /api/event/lucky-draw/issues/receive-all
     */
    public function receiveAll(ReceiveAllRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();

        $this->luckyDrawService->receiveAll($userData->member, (int) $input['issue_id']);

        return ApiResponse::success();
    }
}
