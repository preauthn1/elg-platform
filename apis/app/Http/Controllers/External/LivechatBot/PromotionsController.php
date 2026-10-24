<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\LivechatBot;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\External\LivechatBot\PromotionsRequest;
use App\Services\LivechatBot\PromotionsService;
use Illuminate\Http\JsonResponse;

class PromotionsController extends Controller
{
    public function __construct(
        protected PromotionsService $promotionsService,
    ) {}

    public function __invoke(PromotionsRequest $request): JsonResponse
    {
        $output = $this->promotionsService->getPromotions($request->username());

        return ApiResponse::livechatBotOutput($output);
    }
}
