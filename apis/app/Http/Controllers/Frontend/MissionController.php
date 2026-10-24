<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\Mission\ReceiveRequest;
use App\Http\Resources\Frontend\Mission\MissionListResource;
use App\Services\Frontend\Missions\MissionService;
use Dedoc\Scramble\Attributes\Group;

#[Group('Mission (任務)')]
class MissionController extends Controller
{
    public function __construct(
        protected MissionService $missionService,
    ) {}

    /**
     * 任務列表
     */
    public function list()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $languageId = $userData->languageId;

        // Hardcoded now, use request params in the future, if they want other reset types.
        $missions = $this->missionService->list($member, $languageId);

        return ApiResponse::success(MissionListResource::collection($missions));
    }

    /**
     * 領取任務獎勵
     */
    public function claim(ReceiveRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $input = $request->validated();
        $this->missionService->claim($member, $input['mission_id']);

        return ApiResponse::success();
    }
}
