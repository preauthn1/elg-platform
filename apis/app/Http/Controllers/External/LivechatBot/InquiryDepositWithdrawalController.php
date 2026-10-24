<?php

declare(strict_types=1);

namespace App\Http\Controllers\External\LivechatBot;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\External\LivechatBot\InquiryDepositWithdrawalRequest;
use App\Services\LivechatBot\InquiryDepositWithdrawalService;
use Illuminate\Http\JsonResponse;

class InquiryDepositWithdrawalController extends Controller
{
    public function __construct(
        protected InquiryDepositWithdrawalService $inquiryDepositWithdrawalService,
    ) {}

    public function __invoke(InquiryDepositWithdrawalRequest $request): JsonResponse
    {
        $data = $this->inquiryDepositWithdrawalService->inquiry(
            username: $request->username(),
            transactionType: $request->transactionType(),
        );

        return ApiResponse::livechatBotOutput($data);
    }
}
