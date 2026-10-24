<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\DgPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class Deposit extends AbstractMethod
{
    /** 官方 SGD V2 §7 Payment Type Definition：合法 paymentType 白名單（channel key 動態解析用） */
    private const array V2_PAYMENT_TYPES = ['P2P', 'QR', 'QRKYC'];

    /**
     * V2 掃碼類 paymentType：sender 不帶 bankCode。
     * 官方 §5 Sender Info：bankCode「required for P2P」；QR 純掃碼無銀行選項、
     * QRKYC 無銀行子項（orderType key 即 paymentType、非銀行碼，誤送必錯）。
     */
    private const array V2_QR_PAYMENT_TYPES = ['QR', 'QRKYC'];

    protected InfoData $infoData;

    protected AgentCashFlow $agentCashFlow;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    // ! $agentCashFlow->info 新組（DGPAY）採 SCREAMING_SNAKE，舊組（DG）部分為 PascalCase，兩者由 InfoData::mapInfo 相容
    // {
    //   "API_URL": "https://example.invalid/ajax/api",
    //   "MERCHANT": "dgpay_1568",
    //   "MERCHANT_KEY": "d58cc833-28ab-4b4d-b048-de949c81d6bb",
    //   "CURRENCY": "MYR",
    //   "PROVIDER_ID": "5",
    //   "PROVIDER_TYPE": "20"
    // }
    public function setInfo(array $info): void
    {
        parent::setInfo($info);

        $this->infoData = InfoData::mapInfo($info);
    }

    public function handle(DepositRequestData $params): DepositResponseData
    {
        $member = $params->member;
        $order = $params->order;
        $callbackUrl = $params->callbackUrl;
        $returnUrl = $params->returnUrl;
        $orderType = $order->orderType;

        // 動態路由：binding 靜態 PROVIDER_ID/TYPE（非 0）優先；0/0 時先解析 channel key
        // （新 DGPAY 組，key 編碼 providerId_providerType），再退回舊組的 orderType key 對映
        if ($this->infoData->providerId == 0 && $this->infoData->providerType == 0) {
            $providerData = $this->resolveProviderPair($orderType)
                ?? $this->getProviderData($this->infoData->currency, $orderType);
            $this->infoData->providerId = $providerData['providerId'];
            $this->infoData->providerType = $providerData['providerType'];
        }

        // V2 動態路由（鏡像上方 V1）：binding PAYMENT_CODE 非空時優先（override 全通道）；
        // 空值時由 channel key 解析 paymentType（官方 SGD V2 §7，V1 幣別不受影響）
        if ($this->infoData->version != 'V1' && $this->infoData->paymentCode === '') {
            $this->infoData->paymentCode = $this->resolvePaymentType($orderType);
        }
        $requestData = $this->getRequestData($member, $order, $orderType, $returnUrl);

        if ($this->infoData->version == 'V1') {
            // v1
            $params = [
                'orderId' => $order->order_no,
                'providerId' => $this->infoData->providerId,
                'providerType' => $this->infoData->providerType,
                'currency' => $this->infoData->currency,
                'amount' => $order->amount,
                'callbackUrl' => $callbackUrl,
                'reqDateTime' => Carbon::now()->toFrontendTime()->toDateTimeString(),
                'opCode' => $this->infoData->merchant,
                'data' => json_encode($requestData, JSON_UNESCAPED_SLASHES),
            ];
        } else {
            // v2
            $params = [
                'opCode' => $this->infoData->merchant,
                'orderId' => $order->order_no,
                'paymentType' => $this->infoData->paymentCode,
                'currency' => $this->infoData->currency,
                'amount' => $order->amount,
                'callbackUrl' => $callbackUrl,
                'redirectUrl' => $returnUrl,
                'reqDateTime' => date('Y-m-d H:i:s', strtotime('now +8 hours')),
                'sender' => json_encode($requestData, JSON_UNESCAPED_SLASHES),
            ];
        }
        $endpoint = $this->infoData->version == 'V2' ? '/v2/deposit' : '/deposit';

        $result = $this->sendRequest($endpoint, $params);

        if (!isset($result['code']) || $result['code'] !== '0') {
            $this->throwDepositFailure($result);
        }

        // ! 銀行轉帳
        // {
        //     'code': '0',
        //     'description': 'Success',
        //     'refId': '202507289918578258@dgpay_1568',
        //     'providerRefId': '202507289918578258@dgpay_1568',
        //     'paymentUrl': 'https://example.invalid/pay-auth?authToken=[REDACTED]',
        //     'merchantOrderId': '202507289918578258',
        //     'amount': 100,
        //     'nominalAmount': null,
        //     'amountToDeposit': 100,
        //     'adminFee': 1,
        //     'playerFee': 0,
        //     'agentFee': 1
        // }

        // 紀錄三方訂單號
        // $order->update(['third_party_order_no' => $result['providerRefId']]);

        // IDR OVO：現行 Provider Definition 為 55/20，舊 Provider Definition 26/20 保留相容
        if ($this->infoData->currency == 'IDR' && in_array($this->infoData->providerId, [26, 55], true) && $this->infoData->providerType == 20) {
            // IDR - OVO request 成功后，会在app里直接跳出转账需要approve的notification
            return DepositResponseData::from([
                'type' => DepositResponseType::SUCCESS,
                'url' => '',
                'orderNo' => $order->order_no,
                'providerOrderNo' => $result['providerRefId'] ?? '',
            ]);
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT,
            'url' => $result['paymentUrl'] ? urldecode($result['paymentUrl']) : '',
            'orderNo' => $order->order_no,
            'providerOrderNo' => $result['providerRefId'] ?? '',
        ]);
    }

    private function throwDepositFailure(array $result): never
    {
        $code = (string) ($result['code'] ?? '');
        $description = (string) ($result['description'] ?? '');
        $providerMsg = (string) ($result['providerMsg'] ?? '');
        $message = $providerMsg !== '' ? $providerMsg : ($description !== '' ? $description : 'Unknown error');
        if ($code === '-100') {
            $message = 'Payment channel unavailable, please try another option';
        }

        if ($code === '-175' || str_contains(strtolower($description), 'currently in use')) {
            $message = 'Payment channel busy, please try again later';
        }
        throw new ApiException($this->getDriverName() . ' deposit error: ' . $message);
    }

    // handle() 已完成動態路由解析（channel key → 舊組 key 對映），各幣別 builder 直接取用 infoData
    private function getRequestData(Member|Agent $member, Order $order, OrderType $orderType, string $returnUrl): array
    {
        return match ($this->infoData->currency) {
            'MYR' => $this->buildMyrRequestData($member, $orderType, $returnUrl),
            'SGD' => $this->buildSgdRequestData($member, $order, $orderType, $returnUrl),
            'THB' => $this->buildThbRequestData($member),
            'IDR' => $this->buildIdrRequestData($member, $orderType, $returnUrl),
            default => [],
        };
    }

    private function buildMyrRequestData(Member|Agent $member, OrderType $orderType, string $returnUrl): array
    {
        $providerId = $this->infoData->providerId;
        $providerType = $this->infoData->providerType;

        $data = match (true) {
            // Route2 網銀轉帳：fromBankCode 取銀行子項 key
            $providerId == 5 && $providerType == 10 => [
                'fromBankCode' => $orderType->key,
            ],
            // 舊組 E-Wallet (Duit Now | Touch’n Go | Boost | GrabPay | ShopeePay | Maybank QR)
            $providerId == 17 => [
                'username' => $member->username,
                'code' => $orderType->key,
            ],
            // Route47 P2P：bankCode 取銀行子項 key（官方僅 MBB 支援），無 language
            $providerId == 49 && $providerType == 30 => [
                'username' => $member->username,
                'bankCode' => $orderType->key,
            ],
            // Route47 QR 系列：KYC(10) / TNG(15) / DuitNow(20) / Decimal(25)；QR KYC 另需 username
            $providerId == 49 => [
                'language' => 'EN',
            ] + ($providerType == 10 ? ['username' => $member->username] : []),
            default => [],
        };

        $data['redirectUrl'] = $returnUrl;

        return $data;
    }

    private function buildSgdRequestData(Member|Agent $member, Order $order, OrderType $orderType, string $returnUrl): array
    {
        if ($this->infoData->version == 'V2') {
            return $this->buildSgdV2SenderData($member, $order, $orderType);
        }

        $providerId = $this->infoData->providerId;
        $providerType = $this->infoData->providerType;

        return match (true) {
            // Route 1
            $providerId == 5 && $providerType == 10 => [
                'fromBankCode' => $orderType->key,
                'redirectUrl' => $returnUrl,
            ],
            // Route 2
            $providerId == 12 && $providerType == 20 => [
                'name' => $member->username,
                'redirectUrl' => $returnUrl,
                'bankCode' => $orderType->key,
            ],
            // Route 47 QR
            $providerId == 49 && $providerType == 10 => [
                'language' => 'EN',
                'redirectUrl' => $returnUrl,
            ],
            // Route 47 BankTransfer
            $providerId == 49 && $providerType == 15 => [
                'name' => $member->username,
                'redirectUrl' => $returnUrl,
                'bankCode' => $orderType->key,
            ],
            default => [],
        };
    }

    private function buildSgdV2SenderData(Member|Agent $member, Order $order, OrderType $orderType): array
    {
        $extraContent = json_decode($order->extra_content ?? '', true);

        // 官方 §5 Sender Info：欄位皆標非必填（依商戶協議），共通欄位全帶
        $data = [
            'senderFirstName' => $extraContent['first_name'] ?? '',
            'senderLastName' => $extraContent['last_name'] ?? '',
            'senderNo' => $extraContent['bank_number'] ?? '',
        ];

        // bankCode 反向排除 QR/QRKYC（見 V2_QR_PAYMENT_TYPES）：
        // P2P 取銀行子項 key；舊組 binding（paymentCode 空）行為不變
        if (!in_array($this->infoData->paymentCode, self::V2_QR_PAYMENT_TYPES, true)) {
            $data['bankCode'] = $orderType->key;
        }

        $data['username'] = $member->username;
        $data['phone'] = $member->mobile;

        return $data;
    }

    private function buildThbRequestData(Member|Agent $member): array
    {
        // Qris
        if ($this->infoData->providerId == 11 && $this->infoData->providerType == 10) {
            return [
                'name' => $member->username,
                'email' => ($member->email != '') ? $member->email : $this->infoData->defaultEmail,
                'phone' => $member->mobile,
                'language' => 'EN',
            ];
        }

        return [];
    }

    private function buildIdrRequestData(Member|Agent $member, OrderType $orderType, string $returnUrl): array
    {
        return match (true) {
            // Qris
            $this->infoData->providerType == 10 => [
                'accName' => $member->username,
            ],
            // Virtual Account
            $this->infoData->providerType == 15 => [
                'accName' => $member->username,
                'bankCode' => $orderType->key,
            ],
            // OVO / Dana / ShopeePay
            default => [
                'accName' => $member->username,
                'redirectUrl' => $returnUrl,
                'phoneNum' => $member->mobile,
            ],
        };
    }

    /**
     * 從 channel key 解析 provider pair（新 DGPAY 組，新增通道免改碼）。
     *
     * 父層 order_type.key 以「{providerId}_{providerType}」編碼（如 49_15）；
     * 選中銀行子項時（key 為銀行碼），provider pair 取自其父層。
     * 非此格式（舊組的 DUI/TNG/銀行碼等）回傳 null，交由 getProviderData() 舊表接手。
     *
     * @return array{providerId: int, providerType: int}|null
     */
    private function resolveProviderPair(OrderType $orderType): ?array
    {
        $channel = $orderType->parent > 0 ? $orderType->parentOrderType : $orderType;

        if ($channel === null || !preg_match('/^(\d+)_(\d+)$/', $channel->key, $matches)) {
            return null;
        }

        return [
            'providerId' => (int) $matches[1],
            'providerType' => (int) $matches[2],
        ];
    }

    /**
     * 從 channel key 解析 V2 paymentType（鏡像 resolveProviderPair，新增通道免改碼）。
     *
     * 父層 order_type.key 直接編碼 paymentType（官方 SGD V2 §7：P2P / QR / QRKYC）；
     * 選中銀行子項時（P2P 下的 DBS/UOB/OCBC），paymentType 取自其父層。
     * 非白名單 key（舊組 V2 binding 的銀行碼等）回傳空字串，維持舊組行為（送空 paymentType）。
     */
    private function resolvePaymentType(OrderType $orderType): string
    {
        $channel = $orderType->parent > 0 ? $orderType->parentOrderType : $orderType;

        if ($channel === null || !in_array($channel->key, self::V2_PAYMENT_TYPES, true)) {
            return '';
        }

        return $channel->key;
    }

    private function getProviderData(string $currency, OrderType $orderType): array
    {
        $providerId = 0;
        $providerType = 0;
        if ($currency == 'MYR') {
            switch ($orderType->key) {
                case 'DUI':  // Duit Now
                    $providerId = 17;
                    $providerType = 11;
                    break;
                case 'TNG':  // Touch’n Go
                    $providerId = 17;
                    $providerType = 12;
                    break;
                case 'BOT':  // Boost
                    $providerId = 17;
                    $providerType = 13;
                    break;
                case 'GPY':  // GrabPay
                    $providerId = 17;
                    $providerType = 14;
                    break;
                case 'SPY':  // ShopeePay
                    $providerId = 17;
                    $providerType = 15;
                    break;
                case 'MAE':  // Maybank QR
                    $providerId = 17;
                    $providerType = 16;
                    break;
            }
        }

        return [
            'providerId' => $providerId,
            'providerType' => $providerType,
        ];
    }

    protected function sendRequest(string $url, array $params): array
    {
        $action = $this->getDriverName() . '_deposit';

        $url = rtrim($this->infoData->apiUrl, '/') . $url;
        $params['securityToken'] = $this->generateSignature($params);

        $result = Http::logAction($action)
            ->asForm()
            ->post($url, $params)
            ->throw()
            ->json();

        return $result;
    }

    protected function generateSignature(array $params): string
    {
        if ($this->infoData->version == 'V1') {
            // v1
            $string = $params['orderId'] .
                $params['providerId'] .
                $params['providerType'] .
                $params['currency'] .
                $params['amount'] .
                $params['reqDateTime'] .
                $params['opCode'] .
                $this->infoData->merchantKey;
        } else {
            // v2
            $string = $params['opCode'] .
                $params['orderId'] .
                $params['paymentType'] .
                $params['currency'] .
                $params['amount'] .
                $params['callbackUrl'] .
                $params['redirectUrl'] .
                $params['reqDateTime'] .
                $params['sender'] .
                $this->infoData->merchantKey;
        }

        return strtolower(md5($string));
    }
}
