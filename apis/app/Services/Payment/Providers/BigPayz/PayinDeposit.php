<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Facades\Log;

/**
 * BigPayz Payin V2 (bank transfer): POST /Payin/DepositV2.
 * PascalCase params, Hash = MerchantCode + ItemID + Currency + Amount.
 *
 * @see https://example.invalid/apidocv1.asp#payin-callback-post
 */
class PayinDeposit extends AbstractMethod
{
    use BigPayzTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $member = $request->member;
        $order = $request->order;
        $config = $this->getConfig();
        $amount = (string) $order->amount;
        $currency = $this->agentCashFlow->currency?->code;
        $itemId = $order->order_no;
        $hash = $this->generatePayinHash(
            $config->merchantCode,
            $itemId,
            $currency,
            $amount,
            $config->apiKey
        );

        $params = [
            'MerchantCode' => $config->merchantCode,
            'ReturnURL' => $request->returnUrl,
            'FailedReturnURL' => $request->returnUrl,
            'HTTPPostURL' => $request->callbackUrl,
            'Amount' => $amount,
            'Currency' => $currency,
            'ItemID' => $itemId,
            'ItemDescription' => 'Deposit ' . $itemId,
            'PlayerId' => $member->username,
            'Hash' => $hash,
            'BankCode' => $order->orderType->key,
        ];

        Log::debug(sprintf('%s Payin/DepositV2 代收請求', $this->getDriverName()), [
            'order_no' => $order->order_no,
            'params' => $params,
        ]);

        try {
            $result = $this->sendJsonRequest('/Payin/DepositV2', $params, $config->apiKey, 'payin');
        } catch (\Throwable $e) {
            Log::error(sprintf('%s Payin/DepositV2 代收請求失敗', $this->getDriverName()), [
                'order_no' => $order->order_no,
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $statusEnum = BigPayzStatusCodeEnum::fromResponse($result['status'] ?? null);
        if (!$statusEnum->isInitiated() and !$statusEnum->isSuccess()) {
            $errorMessage = $result['message']
                ?? $result['error']
                ?? (isset($result['error_code'])
                    ? BigPayzPayinErrorCodeEnum::fromValue((int) $result['error_code'])->label()
                    : 'Unknown error');
            Log::error(sprintf('%s Payin/DepositV2 代收失敗（status 非成功）', $this->getDriverName()), [
                'order_no' => $order->order_no,
                'response' => $result,
            ]);
            throw new ApiException(
                sprintf('%s deposit error: %s', $this->getDriverName(), $errorMessage)
            );
        }

        $redirectTo = $this->normalizeRedirectUrl((string) ($result['redirect_to']));
        $token = [REDACTED]'token'] ?? '';
        if ($redirectTo !== '') {
            $order->update(['third_party_order_no' => $token ?: (string) ($result['transaction'] ?? '')]);
        }

        return DepositResponseData::from([
            'type' => 1,
            'url' => $redirectTo,
            'orderNo' => $order->order_no,
            'providerOrderNo' => (string) ($result['transaction'] ?? ''),
        ]);
    }
}
