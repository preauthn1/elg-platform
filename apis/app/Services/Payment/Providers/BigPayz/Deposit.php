<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;

/**
 * BigPayz deposit router: delegates to PayinDeposit (bank transfer) or DuitNowDeposit by order type.
 * - qr.duitnow → DuitNowDeposit (POST /DuitNow/Deposit).
 * - Other (bank code) → PayinDeposit (POST /Payin/DepositV2).
 * Handler is created only when needed (no init of both in constructor).
 */
class Deposit extends AbstractMethod
{
    public function __construct(protected AgentCashFlow $agentCashFlow) {}

    public function handle(DepositRequestData $request): DepositResponseData
    {
        return match ($request->order->orderType->key) {
            'qr.duitnow' => (new DuitNowDeposit($this->agentCashFlow))->handle($request),
            default => (new PayinDeposit($this->agentCashFlow))->handle($request),
        };
    }
}
