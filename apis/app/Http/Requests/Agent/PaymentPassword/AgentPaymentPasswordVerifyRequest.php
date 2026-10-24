<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\PaymentPassword;

use App\Http\Requests\AbstractRequest;

/**
 * 驗證操作者自己的支付密碼（target/tickets#4438，由 API_Agent verifyAgentPaymentPassword 移植）。
 * 欄位刻意叫 payment_password：一般操作紀錄的遮罩清單（MaskTrait）認這個鍵，寫紀錄時會遮成 ***。
 */
class AgentPaymentPasswordVerifyRequest extends AbstractRequest
{
    public function rules(): array
    {
        return [
            /**
             * @var string 支付密碼（長度同舊接口 4～15）
             *
             * @example "123456"
             */
            'payment_password' => 'required|string|between:4,15',
        ];
    }
}
