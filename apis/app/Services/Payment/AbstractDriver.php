<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\Data\BalanceResponseData;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Traits\DriverTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

abstract class AbstractDriver
{
    use DriverTrait;

    public function __construct(protected AgentCashFlow $agentCashFlow)
    {
        Log::debug($this->getDriverName() . ' Driver initialized', [
            'agentCashFlow' => $agentCashFlow->only([
                'id',
                'name',
                'cash_flow_id',
                'currency_id',
                'agent_group_id',
                'status',
            ]),
        ]);
    }

    public function balance(): BalanceResponseData
    {
        if (!isset($this->balance)) {
            throw new ApiException($this->getDriverName() . ' does not support balance');
        }

        return $this->balance->handle();
    }

    public function deposit(DepositRequestData $request): DepositResponseData
    {
        if (!isset($this->deposit)) {
            throw new ApiException($this->getDriverName() . ' does not support deposit');
        }

        return $this->deposit->handle($request);
    }

    public function depositCallback(Request $request): DepositCallbackResponseData
    {
        if (!isset($this->depositCallback)) {
            throw new ApiException($this->getDriverName() . ' does not support deposit callback');
        }

        return $this->depositCallback->handle($request);
    }

    public function withdrawal(WithdrawalRequestData $request): WithdrawalResponseData
    {
        if (!isset($this->withdrawal)) {
            throw new ApiException($this->getDriverName() . ' does not support withdrawal');
        }

        return $this->withdrawal->handle($request);
    }

    public function withdrawalCallback(Request $request): WithdrawalCallbackResponseData
    {
        if (!isset($this->withdrawalCallback)) {
            throw new ApiException($this->getDriverName() . ' does not support withdrawal callback');
        }

        return $this->withdrawalCallback->handle($request);
    }

    /**
     * 三方反查代付訂單狀態
     */
    public function withdrawalQuery(Request $request): array
    {
        if (!isset($this->withdrawalQuery)) {
            throw new ApiException($this->getDriverName() . ' does not support withdrawal query');
        }

        return $this->withdrawalQuery->handle($request);
    }
}
