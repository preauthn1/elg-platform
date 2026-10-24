<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AllPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class Deposit extends AbstractMethod
{
    use AllPayTrait;

    protected AgentCashFlow $agentCashFlow;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    // ! $agentCashFlow->info 應該是這樣的格式
    // {
    //     "APIUrl": "https://example.invalid",
    //     "MerchantNo": "TES507251",
    //     "MerchantKey": "cc743a960b31a68e0c80f94493acca9a",
    //     "hasPaymentCode": 1,
    //     "paymentCode": "P2PDEPOSIT",
    //     "hasBankCode": 0
    // }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;

        $params = $this->buildDepositParams($request);
        $result = $this->sendRequest('', $params);

        // 檢查響應狀態 - 嚴格判斷：必須 code === '0000' 才視為成功
        if (!isset($result['code']) || $result['code'] !== '0000') {
            $errorMessage = $result['msg'] ?? 'Unknown error';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        // 紀錄三方訂單號
        $order->update(['third_party_order_no' => $result['gbpOrderNo'] ?? '']);

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['resultUrl'] ?? '',
            'orderNo' => $order->order_no,
            'providerOrderNo' => '',
        ]);
    }

    /**
     * 構建代收請求參數
     */
    private function buildDepositParams(DepositRequestData $request): array
    {
        $member = $request->member;
        $order = $request->order;
        $callbackUrl = $request->callbackUrl;
        $returnUrl = $request->returnUrl;
        $orderType = $order->orderType;

        $params = [
            'amount' => $order->amount,
            'orderNo' => $order->order_no,
            'orderTime' => Carbon::now()->toFrontendTime()->format('YmdHis'),
            'returnUrl' => $returnUrl,
            'notifyUrl' => $callbackUrl,
            'memberCode' => $member->username, // 緬甸通道不用填,請提交空白即可
            'mobile' => $member->mobile,
        ];

        if (isset($this->info['disabled_member_code'])
            && $this->info['disabled_member_code'] == 1) {
            unset($params['memberCode']);
        }

        if (isset($this->info['hasPaymentCode']) && $this->info['hasPaymentCode'] == 1) {
            $params['paymentCode'] = $orderType->key;
        }

        if (isset($this->info['hasBankCode']) && $this->info['hasBankCode'] == 1) {
            $params['paymentCode'] = $this->info['paymentCode'];
            $params['bankCode'] = $orderType->key;
        }

        Log::debug('AllPay Deposit Request Params:', $params);

        return $params;
    }
}
