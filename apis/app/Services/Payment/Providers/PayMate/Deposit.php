<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayMate;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class Deposit extends AbstractMethod
{
    use PayMateTrait;

    protected AgentCashFlow $agentCashFlow;

    /**
     * paymentCode 對應的下單端點預設值（api.md 3.1 僅記載 /qrh5，/cashier 為 PayMate 商戶
     * PM134126 另行提供的 P2P 系代收端點，見商戶開通資料）
     *
     * SE PayCenter 系金流商同協定、但端點依「商戶開通配置」而異（SpeedPay 商戶兩通道皆走
     * /qrh5），故實際端點以 info[ENDPOINT_BY_PAYMENT_CODE] 為優先，本常數僅為未配置時的回退。
     */
    private const array ENDPOINT_BY_PAYMENT_CODE = [
        'DUITNOWP2P' => '/qrh5',
        'P2PDEPOSIT' => '/cashier',
    ];

    /** info 中「paymentCode → 端點」商戶專屬對照的欄位名 */
    private const string ENDPOINT_MAP_FIELD = 'ENDPOINT_BY_PAYMENT_CODE';

    /** 對照表與常數皆未命中時的端點（api.md 3.1 原文端點） */
    private const string DEFAULT_ENDPOINT = '/qrh5';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(DepositRequestData $request): DepositResponseData
    {
        $order = $request->order;
        $params = $this->buildDepositParams($request);
        $endpoint = $this->resolveDepositEndpoint((string) $params['paymentCode']);

        $result = $this->sendRequest($endpoint, $params);

        // 見 api.md 3.1 API Response：code=0000 代表成功，其他皆為失敗
        if (!isset($result['code']) || $result['code'] !== '0000') {
            throw new ApiException(sprintf(
                '%s deposit error: %s',
                $this->getDriverName(),
                $result['msg'] ?? 'Unknown error'
            ));
        }

        $resultUrl = $result['resultUrl'] ?? '';

        if (empty($resultUrl)) {
            throw new ApiException(sprintf('%s deposit error: missing resultUrl', $this->getDriverName()));
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $resultUrl,
            'html' => null,
            'orderNo' => $order->order_no,
            'providerOrderNo' => $result['gbpOrderNo'] ?? '',
        ]);
    }

    /**
     * 解析 paymentCode 對應的代收端點
     *
     * 優先取 agent_cash_flows.info 的商戶專屬對照（同一 Driver 供多商戶共用時，各商戶的端點
     * 開通配置不同），未配置或該 paymentCode 未列入時回退類別常數，最後回退 api.md 原文端點。
     *
     * ! 對照表「完全未配置」是 PayMate 商戶的正常路徑（其 example.invalid 無此鍵），故不記錄告警；
     * ! 只有「已配置對照表但該 paymentCode 缺漏或值不是合法路徑」才是誤配置，需記 warning
     * ! ——否則主力通道被靜默導向錯誤端點時，只能靠對帳才發現。
     */
    private function resolveDepositEndpoint(string $paymentCode): string
    {
        $endpointMap = $this->info[self::ENDPOINT_MAP_FIELD] ?? null;
        $fallback = self::ENDPOINT_BY_PAYMENT_CODE[$paymentCode] ?? self::DEFAULT_ENDPOINT;

        if (!is_array($endpointMap)) {
            return $fallback;
        }

        $configured = $endpointMap[$paymentCode] ?? null;

        if (is_string($configured) && $this->isValidEndpointPath($configured)) {
            return $configured;
        }

        Log::warning($this->getDriverName() . ' deposit endpoint map misconfigured, fell back', [
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'payment_code' => $paymentCode,
            'configured_type' => get_debug_type($configured),
            'fallback_endpoint' => $fallback,
        ]);

        return $fallback;
    }

    /**
     * 端點必須是相對路徑：sendRequestRaw 以 API_URL . $path 拼接，若填入完整 URL
     * （如舊系統 info 存的含尾綴 URL）會拼成畸形位址，帶 userinfo 的值更可把請求
     * 連同簽名導向他站，故限定以 / 開頭且不含 scheme／userinfo／protocol-relative 前綴。
     */
    private function isValidEndpointPath(string $endpoint): bool
    {
        return str_starts_with($endpoint, '/')
            && !str_starts_with($endpoint, '//')
            && !str_contains($endpoint, '@')
            && !str_contains($endpoint, ':');
    }

    /**
     * 構建代收請求參數（見 api.md 3.1 API Request）
     *
     * orderType->key 即支付產品代碼 paymentCode（見 5.3 PaymentCode 渠道代碼表），
     * 有父層 orderType 時代表銀行直跳選項，父層 key 為 paymentCode、本層 key 為 bankCode
     */
    private function buildDepositParams(DepositRequestData $request): array
    {
        $member = $request->member;
        $order = $request->order;
        $orderType = $order->orderType;
        $parentOrderType = $orderType->parentOrderType;

        $params = [
            'amount' => Common::numFloor($order->amount, 2),
            'paymentCode' => $parentOrderType ? $parentOrderType->key : $orderType->key,
            'orderNo' => $order->order_no,
            'orderTime' => Carbon::now()->toFrontendTime()->format('YmdHis'),
            'returnUrl' => $request->returnUrl,
            'notifyUrl' => $request->callbackUrl,
            'memberCode' => $member->username,
        ];

        if ($parentOrderType) {
            $params['bankCode'] = $orderType->key;
        }

        return $params;
    }
}
