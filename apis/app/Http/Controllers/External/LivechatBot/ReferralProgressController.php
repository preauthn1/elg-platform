<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\LivechatBot;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\External\LivechatBot\ReferralProgressRequest;
use App\Services\LivechatBot\ReferralProgressService;
use Illuminate\Http\JsonResponse;

class ReferralProgressController extends Controller
{
    public function __construct(
        protected ReferralProgressService $referralProgressService,
    ) {}

    public function __invoke(ReferralProgressRequest $request): JsonResponse
    {
        $output = $this->referralProgressService->getProgress($request->username());

        return ApiResponse::livechatBotOutput($output);
    }
}
