<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\EpicPay;

use App\Enums\DepositResponseType;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\DeviceTypeEnum;
use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;

class Deposit extends AbstractMethod
{
    use EpicPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $action = 'getDPLink';
        $order = $request->order;

        $params = $this->buildDepositParams($request, $action);
        $result = $this->sendRequest('/a/' . $action, $params);

        // 檢查響應狀態 - 嚴格判斷：必須 status === 0 才視為成功
        if (!isset($result['status']) || $result['status'] !== 0) {
            $errorMessage = $result['msg'] ?? 'Unknown error';
            throw new ApiException($this->getDriverName() . ' deposit error: ' . $errorMessage);
        }

        // 檢查響應數據
        if (!isset($result['d']['url'])) {
            throw new ApiException($this->getDriverName() . ' deposit error: missing payment url');
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['d']['url'],
            'orderNo' => $order->order_no,
            'providerOrderNo' => '',
        ]);
    }

    /**
     * 構建代收請求參數
     */
    private function buildDepositParams(DepositRequestData $request, string $action): array
    {
        $member = $request->member;
        $order = $request->order;
        $callbackUrl = $request->callbackUrl;
        $returnUrl = $request->returnUrl;
        $userIP = $request->ip;

        // 裝置渠道
        $userData = Context::getHidden(ContextKeyEnum::USER_DATA->value);
        $deviceType = $userData?->deviceType ?? DeviceTypeEnum::PC->value;
        $channel = $deviceType === DeviceTypeEnum::MOBILE->value ? 2 : 1;

        // 是否實名驗證
        $isVerify = $member->first_name && $member->realname_validate ? 1 : 0;

        $params = [
            'mCode' => $this->info['MERCHANT_CODE'],
            'ts' => Carbon::now()->timestamp,
            'bCode' => $order->orderType->key ?? '', // 银行代码
            'amt' => $order->amount,
            'user' => $member->username,
            'userFn' => $member->first_name,
            'trxid' => $order->order_no,
            'notifyUrl' => $callbackUrl,
            'webUrl' => $returnUrl,
            'isverify' => $isVerify,
            'userIP' => $userIP,
            'channel' => $channel,
        ];

        $params['token'] = $this->generateToken($params, $action, $this->info['SECRET_KEY']);

        return $params;
    }
}
