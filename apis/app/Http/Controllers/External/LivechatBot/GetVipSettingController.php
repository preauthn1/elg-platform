<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\LivechatBot;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\External\LivechatBot\GetVipSettingRequest;
use App\Services\LivechatBot\GetVipSettingService;
use Illuminate\Http\JsonResponse;

class GetVipSettingController extends Controller
{
    public function __construct(
        protected GetVipSettingService $getVipSettingService,
    ) {}

    public function __invoke(GetVipSettingRequest $request): JsonResponse
    {
        $output = $this->getVipSettingService->getVipSetting($request->username());

        return ApiResponse::livechatBotOutput($output);
    }
}
