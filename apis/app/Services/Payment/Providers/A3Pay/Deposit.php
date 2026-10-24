<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\A3Pay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use Illuminate\Support\Facades\Log;

/**
 * A3Pay 代收（Submit Payin）。
 *
 * 請求 Data 欄位是閘道新版命名（MID／MTransactionId／PayinAmount…），回應鍵 paymentOrderId／paymentURL／paymentQRCode；
 * 信封／AES／RSA 走 A3PayTrait。
 *
 * SGD 必填 FromCustomerAccountHolderName（api.md Submit Payin）；PaymentMethodId 取 OrderType key（OB／BQR）。
 *
 * @knowledge rules/provider-a3pay
 */
class Deposit extends AbstractMethod
{
    use A3PayTrait;

    private const string ENDPOINT = '/api/Payin/CreatePayin';

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

        $params = $this->buildPayinParams($request);

        $result = $this->sendRequest(self::ENDPOINT, $params);

        if (($result['isSuccess'] ?? null) !== true) {
            throw new ApiException(sprintf('%s deposit error: %s', $this->getDriverName(), $this->describeError($result)));
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        // 回應鍵名文件版與實測舊版都收（A3PayTrait::dataValue 說明）
        $paymentUrl = (string) ($this->dataValue($data, 'paymentURL', 'url') ?? '');
        $qrCode = (string) ($this->dataValue($data, 'paymentQRCode', 'qrCode') ?? '');
        $providerOrderNo = (string) ($this->dataValue($data, 'paymentOrderId', 'transactionId') ?? '');
        $actualAmountRaw = $this->dataValue($data, 'payinActualAmount', 'depositActualAmount');

        if ($paymentUrl === '' && $qrCode === '') {
            throw new ApiException($this->getDriverName() . ' deposit error: missing paymentURL and paymentQRCode');
        }

        if ($providerOrderNo !== '') {
            $order->update(['third_party_order_no' => $providerOrderNo]);
        }

        // SGD 同收款帳戶同金額時三方會微幅上調實付金額（如 100 → 100.01）；回調 Amount 是原始還是實付金額
        // 廠商文件沒說（open-questions.md Q1），先記錄以便對照回調金額不符的人工單
        if (is_numeric($actualAmountRaw)) {
            $actualAmount = Common::numAdd('0', (string) $actualAmountRaw, 4);

            if (Common::numCompare((string) $order->amount, $actualAmount, 2) !== 0) {
                Log::warning($this->getDriverName() . ' payinActualAmount differs from order amount', [
                    'order_no' => $order->order_no,
                    'order_amount' => $order->amount,
                    'payin_actual_amount' => $actualAmount,
                ]);
            }
        }

        // 一律導向收銀台 paymentURL（OB／BQR 都會給，收銀台自己會顯示 QR）。
        // 2026-09-24 dev 實測：BQR 同時回 paymentURL 與 paymentQRCode（base64 data URI），
        // 若回 QR 型別前台沒有對應處理、畫面沒反應；只有沒給 URL 時才退回 QR 圖，用前台認得的 QR_CODE_IMAGE_PATH。
        $type = DepositResponseType::URL_REDIRECT;
        $url = $paymentUrl;

        if ($paymentUrl === '' && $qrCode !== '') {
            $type = DepositResponseType::QR_CODE_IMAGE_PATH;
            $url = $qrCode;
        }

        return DepositResponseData::from([
            'type' => $type,
            'url' => $url,
            'orderNo' => $order->order_no,
            'providerOrderNo' => $providerOrderNo,
        ]);
    }

    /**
     * 構建 Submit Payin 的 Data Parameter（api.md）
     */
    private function buildPayinParams(DepositRequestData $request): array
    {
        $order = $request->order;
        $member = $request->member;

        return [
            'MID' => $this->info[self::FIELD_MERCHANT_CODE],
            'MTransactionId' => $order->order_no,
            'MCustId' => $member->username,
            'PayinAmount' => sprintf('%.2f', $order->amount),
            'CurrCode' => $this->info[self::FIELD_CURRENCY],
            'RequestIPAddress' => $request->ip,
            'CallbackURL' => $request->callbackUrl,
            'ReturnClientURL' => $request->returnUrl,
            // OB＝Online banking、BQR＝QR code，對應 providers/A3Pay.php 的 deposit channel key
            'PaymentMethodId' => $order->orderType->key,
            // SGD 必填；會員未填英文姓名時退回帳號，避免被三方以缺欄位拒單
            'FromCustomerAccountHolderName' => $this->resolveAccountHolderName($member),
        ];
    }

    private function resolveAccountHolderName(Member|Agent $member): string
    {
        $fullName = trim(sprintf('%s %s', (string) ($member->first_name ?? ''), (string) ($member->last_name ?? '')));

        return $fullName !== '' ? $fullName : (string) $member->username;
    }
}
