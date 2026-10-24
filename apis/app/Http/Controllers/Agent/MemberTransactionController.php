<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Agent\MemberTransactionOptionService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 會員開洗分（target/tickets#4438 先搬彈窗的前置資料；開洗分本身由 #4333 批次 2 搬進來）。
 */
#[Group('member (會員開洗分)')]
class MemberTransactionController extends Controller
{
    public function __construct(
        private readonly MemberTransactionOptionService $optionService,
    ) {}

    /**
     * 交易是否維護中
     *
     * 維護中前端隱藏開洗分入口。回 `{maintaining: bool}`。
     */
    public function maintenance(): JsonResponse
    {
        return ApiResponse::success(['maintaining' => $this->optionService->isTransactionMaintaining()]);
    }

    /**
     * 開洗分可選的銀行帳號
     *
     * 操作者主帳號支付組別、自助開分金流底下啟用中的帳號；前端依 is_deposit／is_withdrawal 分存入、取出兩個下拉。
     */
    public function bankAccounts(): JsonResponse
    {
        return ApiResponse::success($this->optionService->bankAccounts($this->getAgentContext()->agent));
    }
}
