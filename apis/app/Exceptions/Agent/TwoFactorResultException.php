<?php

declare(strict_types=1);

namespace App\Exceptions\Agent;

use App\Enums\Agent\TwoFactorResultCodeEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * 代理 Google 2FA 的業務失敗（target/tickets#4518）。
 *
 * 以 HTTP 200 回 API_Agent 相容的業務碼（理由見 TwoFactorResultCodeEnum）：覆寫 render()，
 * 不沿用 ApiException 的 HTTP 4xx，前端彈窗才能就地顯示「驗證碼錯誤」讓人重試。
 * 必須繼承 ApiException：業務拒絕（輸錯驗證碼等）才會被例外回報靜音，
 * 否則每輸錯一次就寫一筆 error log 並觸發例外告警（見 bootstrap/app.php 的 report callback）。
 * 權限不足（9003）、帳號不存在（1005）這類不需就地顯示的直接走 ApiException。
 */
class TwoFactorResultException extends ApiException
{
    public function __construct(
        public readonly TwoFactorResultCodeEnum $result,
    ) {
        parent::__construct(__($result->messageKey()), $result->value);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::customCode($this->result->value, $this->getMessage());
    }
}
