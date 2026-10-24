<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AkPay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;

/**
 * AkPay 代收
 *
 * 參照官方文檔：https://example.invalid/view/22077629/2s9Ykt4ycb#9eb8f7ec-ddaa-4983-92c8-35f1bb509251
 */
class Deposit extends AbstractMethod
{
    use AkPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    // 定義返回三方的信息
    protected string|array $notifySuccessText = [
        'success' => true,
    ];

    protected string|array $notifyFailText = [
        'success' => false,
    ];

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;

        $params = $this->buildDepositParams($request);

        $result = $this->sendRequest('/api/order/fundin', $params);

        if (!isset($result['success']) || $result['success'] !== true) {
            $dataErrors = $result['data'] ?? [];
            $firstFieldErrors = !empty($dataErrors) ? reset($dataErrors) : [];
            $errorMessage = $firstFieldErrors[0] ?? $result['message'] ?? 'Unknown error';

            throw new ApiException($this->getDriverName() . ' deposit failed: ' . $errorMessage);
        }

        // 紀錄三方訂單號
        $providerOrderNo = $result['data']['tx_id'] ?? '';
        $order->update(['third_party_order_no' => $providerOrderNo]);

        return DepositResponseData::from([
            'type' => 1,
            'url' => $result['data']['url'],
            'orderNo' => $order->order_no,
            'providerOrderNo' => $providerOrderNo,
        ]);
    }

    /**
     * 構建代收請求參數
     *
     * @throws \Exception
     */
    private function buildDepositParams(DepositRequestData $request): array
    {
        $member = $request->member;
        $order = $request->order;
        $callbackUrl = $request->callbackUrl;
        $returnUrl = $request->returnUrl;

        // 判斷是否為子層 OrderType（有 parent），透過關聯取得父層 OrderType
        $parentOrderType = $order->orderType->parentOrderType;
        $paymentMethod = $parentOrderType ? $parentOrderType->key : $order->orderType->key;

        $params = [
            'merchant_code' => $this->info['MERCHANT_CODE'],
            'source_id' => $order->order_no,
            'full_name' => $member->username,
            'customer' => $member->id,
            'payment_method' => $paymentMethod, // 支付渠道
            'amount' => Common::numFloor($order->amount, 2), // 保留兩位小數
            'currency' => $this->info['CURRENCY'] ?? 'MYR',
            'callback_url' => $callbackUrl,
            'redirect_url' => $returnUrl,
        ];

        // 銀行選項
        if ($parentOrderType) {
            $params['bank_code'] = $order->orderType->key;
        }

        return $params;
    }
}
