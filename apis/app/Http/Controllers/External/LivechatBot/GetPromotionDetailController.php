<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\LivechatBot;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\External\LivechatBot\GetPromotionDetailRequest;
use App\Services\LivechatBot\GetPromotionDetailService;
use Illuminate\Http\JsonResponse;

class GetPromotionDetailController extends Controller
{
    public function __construct(
        protected GetPromotionDetailService $service,
    ) {}

    public function __invoke(GetPromotionDetailRequest $request): JsonResponse
    {
        $output = $this->service->getPromotionDetail(
            username: $request->username(),
            promotionName: $request->promotionName(),
        );

        return ApiResponse::livechatBotOutput($output);
    }
}
