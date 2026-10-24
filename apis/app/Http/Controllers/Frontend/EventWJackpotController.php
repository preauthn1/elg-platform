<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\EventWJackpot\IssueDetailRequest;
use App\Http\Requests\Frontend\EventWJackpot\IssueTicketsRequest;
use App\Http\Requests\Frontend\EventWJackpot\ReceiveAllRequest;
use App\Http\Requests\Frontend\EventWJackpot\ReceiveRequest;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\WJackpotService;
use Dedoc\Scramble\Attributes\Group;

#[Group('EventWJackpot (W-Jackpot 抽獎券活動)')]
class EventWJackpotController extends Controller
{
    public function __construct(
        private WJackpotService $wJackpotService,
    ) {}

    /**
     * W-Jackpot 期數列表
     *
     * - 只回最近 3 期（issues_1272 領取時間限制）
     *
     * OLD: /api/event/wjackpot/issues
     */
    public function issueList()
    {
        $userData = $this->getUserContext();

        return ApiResponse::success($this->wJackpotService->getIssueList($userData->agent));
    }

    /**
     * W-Jackpot 期數明細
     *
     * OLD: /api/event/wjackpot/issues/detail
     */
    public function issueDetail(IssueDetailRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();

        $data = $this->wJackpotService->getIssueDetail((int) $input['issue_id'], $userData->member->id);

        return ApiResponse::success($data);
    }

    /**
     * W-Jackpot 每期抽獎券明細
     *
     * OLD: /api/event/wjackpot/issues/tickets
     */
    public function issueTickets(IssueTicketsRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();

        $result = $this->wJackpotService->getIssueTickets(
            (int) $input['issue_id'],
            $userData->member->id,
            $userData->languageId,
            (int) $input['per_page'],
            (int) $input['page'],
        );

        return ApiResponse::success([
            'result_number' => $result['result_number'],
            'result_number_2' => $result['result_number_2'],
            'result_number_3' => $result['result_number_3'],
            'result_number_4' => $result['result_number_4'],
            'result_number_5' => $result['result_number_5'],
            'items' => $result['paginator']->items(),
            'pagination' => new OnlyPaginateResource($result['paginator']),
        ]);
    }

    /**
     * W-Jackpot 領取獎項
     *
     * OLD: /api/event/wjackpot/issues/receive
     */
    public function receive(ReceiveRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();

        $this->wJackpotService->receive($userData->member, (int) $input['ticket_id']);

        return ApiResponse::success();
    }

    /**
     * W-Jackpot 該期獎項全部領取
     *
     * - 查無可領取抽獎券時仍視為成功，與舊系統 API_Frontend 相同
     *
     * OLD: /api/event/wjackpot/issues/receive-all
     */
    public function receiveAll(ReceiveAllRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();

        $this->wJackpotService->receiveAll($userData->member, (int) $input['issue_id']);

        return ApiResponse::success();
    }

    /**
     * W-Jackpot 6D 期數列表
     *
     * - 只回最近 3 期
     *
     * OLD: /api/event/wjackpot6d/issues
     */
    public function issueList6d()
    {
        $userData = $this->getUserContext();

        return ApiResponse::success($this->wJackpotService->get6dIssueList($userData->agent));
    }

    /**
     * W-Jackpot 6D 期數明細
     *
     * OLD: /api/event/wjackpot6d/issues/detail
     */
    public function issueDetail6d(IssueDetailRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();

        $data = $this->wJackpotService->get6dIssueDetail((int) $input['issue_id'], $userData->member->id);

        return ApiResponse::success($data);
    }

    /**
     * W-Jackpot 6D 每期抽獎券明細
     *
     * OLD: /api/event/wjackpot6d/issues/tickets
     */
    public function issueTickets6d(IssueTicketsRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();

        $result = $this->wJackpotService->get6dIssueTickets(
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
     * W-Jackpot 6D 領取獎項
     *
     * OLD: /api/event/wjackpot6d/issues/receive
     */
    public function receive6d(ReceiveRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();

        $this->wJackpotService->receive6d($userData->member, (int) $input['ticket_id']);

        return ApiResponse::success();
    }

    /**
     * W-Jackpot 6D 該期獎項全部領取
     *
     * OLD: /api/event/wjackpot6d/issues/receive-all
     */
    public function receive6dAll(ReceiveAllRequest $request)
    {
        $input = $request->validated();
        $userData = $this->getUserContext();

        $this->wJackpotService->receive6dAll($userData->member, (int) $input['issue_id']);

        return ApiResponse::success();
    }
}
