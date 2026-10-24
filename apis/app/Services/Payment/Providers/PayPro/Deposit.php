<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayPro;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Facades\Log;

class Deposit extends AbstractMethod
{
    use PayProTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    // ! $agentCashFlow->info 應該是這樣的格式
    // {
    //     "API_URL": "https://sandbox-api.paypro.tech/indonesia-pay",
    //     "MERCHANT_NO": "0000000001",
    //     "MERCHANT_KEY": "your_merchant_key"
    // }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;

        $params = $this->buildDepositParams($request);
        $result = $this->sendRequest('/v1/paypro/paymentLink', $params);

        // 檢查響應狀態 - 嚴格判斷：必須 code === '0000' 才視為成功
        if (!isset($result['code']) || $result['code'] !== '0000') {
            $errorMessage = $result['msg'] ?? ($result['data']['desc'] ?? 'Unknown error');
            Log::error($this->getDriverName() . ' 代收 API 返回錯誤', [
                'response' => $result,
            ]);
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        $data = $result['data'] ?? [];

        // 紀錄三方訂單號
        $order->update([
            'third_party_order_no' => $data['platFormTradeNo'],
        ]);

        $type = DepositResponseType::URL_REDIRECT; // 默認為 URL 跳轉
        $url = $data['paymentUrl'];
        $status = $data['status'];

        if (!$url || $status === '00029') {
            Log::error($this->getDriverName() . ' 代收 paymentUrl 無效或狀態被拒', [
                'status' => $status,
                'paymentUrl' => $url,
            ]);
            $errorMessage = $data['desc'] ?? 'the payment was rejected';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        return DepositResponseData::from([
            'type' => $type,
            'url' => $url,
            'orderNo' => $order->order_no,
            'providerOrderNo' => $data['platFormTradeNo'],
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

        if ($orderType->parent > 0) {
            $channelCode = $orderType->parentOrderType->key;
        } else {
            $channelCode = $orderType->key;
        }
        $wayCode = $orderType->key;

        // 印尼手機號, 先寫死
        $mobile = '62812345678';
        $email = 'user@example.invalid';

        $params = [
            'timestamp' => $this->getTimestamp(),
            'tradeNo' => $order->order_no,
            'username' => $member->username ?? '',
            'mobile' => $mobile,
            'email' => $email,
            'channelCode' => $channelCode,
            'wayCode' => $wayCode,
            'amount' => (float) Common::numFloor($order->amount, 0), // PayPro 要求 float，不支持小數，轉為整數
            'notifyUrl' => $callbackUrl,
            'returnUrl' => $returnUrl,
        ];

        Log::debug($this->getDriverName() . ' 代收請求參數', $this->maskSensitiveParams($params));

        return $params;
    }
}
