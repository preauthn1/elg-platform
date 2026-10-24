<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GMPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;

/**
 * GMPay 代收發起（standalone，不繼承 MajesticPay）。
 *
 * 送 POST /api/create_deposit，發起簽名走 GMPayTrait::generateHash
 * （ref_no + number_format(amount,2) + currency）。與 MajesticPay 目前協議相同，
 * 但工單 #2435 起兩商已解耦、各自獨立演進。
 */
class Deposit extends AbstractMethod
{
    use GMPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;

        $params = $this->buildDepositParams($request);
        $params['hash'] = $this->generateHash($params, $this->info['SECRET_KEY']);

        $result = $this->sendRequest('/api/create_deposit', $params);

        // 檢查響應狀態 - 嚴格判斷：必須 status === true 才視為成功
        if (!isset($result['status']) || $result['status'] !== true) {
            $errorMessage = $result['message'] ?? 'Unknown error';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        // 紀錄三方訂單號
        $order->update(['third_party_order_no' => $result['data']['transaction_reference']]);

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['data']['payment_link'] ?: '',
            'orderNo' => $order->order_no,
            'providerOrderNo' => $result['data']['transaction_reference'] ?: '',
        ]);
    }

    /**
     * 構建代收請求參數
     *
     * payment_method_id：
     * - Online Banking = 3
     * - PayNow / 其他 SGD 通道依 orderType->key
     *
     * @return array<string, mixed>
     */
    private function buildDepositParams(DepositRequestData $request): array
    {
        $member = $request->member;
        $order = $request->order;
        $callbackUrl = $request->callbackUrl;
        $returnUrl = $request->returnUrl;

        return [
            'api_key' => $this->info['API_KEY'],
            'ref_no' => $order->order_no,
            'amount' => $order->amount,
            'payment_method_id' => $order->orderType->key,
            'currency' => $this->info['CURRENCY'],
            'return_url' => $returnUrl,
            'callback_url' => $callbackUrl,
            'player_id' => $member->id,
        ];
    }
}
