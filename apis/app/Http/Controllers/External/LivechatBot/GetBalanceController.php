<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\LivechatBot;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\External\LivechatBot\GetBalanceRequest;
use App\Services\LivechatBot\GetBalanceService;
use Illuminate\Http\JsonResponse;

class GetBalanceController extends Controller
{
    public function __construct(
        protected GetBalanceService $getBalanceService,
    ) {}

    public function __invoke(GetBalanceRequest $request): JsonResponse
    {
        $data = $this->getBalanceService->getBalance($request->username());

        return ApiResponse::livechatBotOutput($data);
    }
}
