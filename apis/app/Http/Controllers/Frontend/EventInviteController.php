<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Frontend\EventInvite\EventInviteListResource;
use App\Http\Resources\Frontend\EventInvite\EventInviteSettingResource;
use App\Services\Frontend\EventInvite\EventInviteService;

class EventInviteController extends Controller
{
    public function __construct(
        private EventInviteService $eventInviteService
    ) {}

    public function list()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $result = $this->eventInviteService->list($member, $userData->languageId);

        return ApiResponse::success([
            'setting' => EventInviteSettingResource::make($result['setting']),
            'reward_card' => EventInviteListResource::collection($result['joins']),
        ]);
    }

    public function receive()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $result = $this->eventInviteService->receive($member, $userData->languageId);

        return ApiResponse::success([
            'setting' => EventInviteSettingResource::make($result['setting']),
            'reward_card' => EventInviteListResource::collection($result['joins']),
        ]);
    }
}
