<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\LivechatBot;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\External\LivechatBot\GetWithdrawBalanceRequest;
use App\Services\LivechatBot\GetWithdrawBalanceService;
use Illuminate\Http\JsonResponse;

class GetWithdrawBalanceController extends Controller
{
    public function __construct(
        protected GetWithdrawBalanceService $getWithdrawBalanceService,
    ) {}

    public function __invoke(GetWithdrawBalanceRequest $request): JsonResponse
    {
        $output = $this->getWithdrawBalanceService->getWithdrawBalance($request->username());

        return ApiResponse::livechatBotOutput($output);
    }
}
