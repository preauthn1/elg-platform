<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VtPay;

use App\Enums\CurrencyEnum;
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
    use VtPayTrait;

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

        $result = $this->sendRequest($this->info['API_URL'] . '/SH/sendPay', $params);

        // 檢查響應狀態：error_code 為 200 視為成功（兼容字串 "200"）
        if (!isset($result['error_code']) || (int) $result['error_code'] !== 200) {
            $errorMessage = $result['message'] ?? 'Unknown error';

            Log::error($this->getDriverName() . ' deposit failed', [
                'order_no' => $order->order_no,
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'amount' => $order->amount,
                'error' => $errorMessage,
                'result' => $result,
            ]);

            throw new ApiException(
                sprintf('%s deposit error: %s', $this->getDriverName(), $errorMessage)
            );
        }

        // 提取返回的 URL（文檔明確說明是數組）
        $url = '';
        if (isset($result['data']['url'])) {
            if (is_array($result['data']['url'])) {
                // 如果是數組，取第一個URL
                $url = $result['data']['url'][0] ?? '';
            } else {
                $url = $result['data']['url'];
            }
        }

        if (empty($url)) {
            Log::error($this->getDriverName() . ' deposit failed: missing payment URL', [
                'order_no' => $order->order_no,
                'result' => $result,
            ]);

            throw new ApiException(
                sprintf('%s deposit error: missing payment URL', $this->getDriverName())
            );
        }

        return DepositResponseData::from([
            'type' => DepositResponseType::URL_REDIRECT, // URL 跳轉類型
            'url' => urldecode($url),
            'orderNo' => $order->order_no,
            'providerOrderNo' => $result['data']['order_id'] ?? '',
        ]);
    }

    /**
     * 構建代收請求參數
     *
     * 根據 VtPay API 文檔：/SH/sendPay
     * 參考：VtPay.openapi.json
     *
     * Cards 充值額外欄位（馬來西亞專用）：
     * - card_holder_name: 持卡人姓名
     * - card_number: 銀行卡號
     * - card_exp_mn: 銀行卡過期月份
     * - card_exp_yy: 銀行卡過期年份（當前年份後兩位，例：25 代表 2025）
     * - card_cvv: 安全碼
     */
    private function buildDepositParams(DepositRequestData $request): array
    {
        $member = $request->member;
        $order = $request->order;
        $callbackUrl = $request->callbackUrl;

        // 判斷是否為子層 OrderType（有 parent），透過關聯取得父層 OrderType
        $parentOrderType = $order->orderType->parentOrderType;
        $orderTypeKey = $parentOrderType ? $parentOrderType->key : $order->orderType->key;

        $params = [
            // 必填：商戶訂單號（該值需在商戶系統內唯一存在）
            'sh_order_no' => $order->order_no,

            // 必填：訂單提交金額
            'order_amount' => Common::numFloor($order->amount, 2),

            // 必填：訂單充值類型
            // 100: 网银类型
            // QRPay: QR Code 扫码充值
            // QRZaloPay: Zalo 扫码充值（仅限越南）
            // QRMonopay: Momo 扫码充值（仅限越南）
            // QRViettelpay: Viettelpay 扫码充值（仅限越南）
            // lbt: LBT充值（仅限新加坡）
            // FPX: FPX充值（仅限马来西亚）
            // TNG: TNG充值（仅限马来西亚）
            // Cards: Cards充值（仅限马来西亚）
            'order_type' => $orderTypeKey,

            // 異步通知商戶地址（無需進行 encode url 處理）
            'notify_url' => $callbackUrl,

            // 備註（可選）
            'note' => '',
        ];

        // 如果有 parent，表示是銀行選項，需要設置 bank_code
        if ($parentOrderType) {
            $params['bank_code'] = $order->orderType->key;  // 使用當前子層的 key 作為 bank_code
        }

        $extraFields = Common::safeJsonDecode($order->extra_content);

        // FPX、TNG、Cards 充值需要額外的客戶信息
        if ($order->currency_id == CurrencyEnum::MYR->value && in_array($orderTypeKey, ['FPX', 'TNG', 'Cards'])) {
            // 優先使用 extraFields，如果沒有則使用會員資料
            $params['customer_name'] = $extraFields['customer_name'] ?? $member->username;
            $params['customer_email'] = $extraFields['customer_email'] ?? $member->email;
            $params['customer_phone'] = $extraFields['customer_phone'] ?? $member->mobile;
        }

        // Cards 充值需要額外的信用卡信息
        if ($order->currency_id == CurrencyEnum::MYR->value && $orderTypeKey === 'Cards') {
            $cardFields = $this->validateAndFormatCardFields($extraFields);
            $params = array_merge($params, $cardFields);
        }

        // 注意：使用 AES 加密，不需要手動添加簽名
        // 整個參數數組會被 JSON 編碼後 AES 加密

        return $params;
    }

    /**
     * 驗證並格式化信用卡欄位
     *
     * @param  array  $extraFields  訂單額外欄位
     * @return array 格式化後的信用卡參數
     *
     * @throws ApiException 當欄位缺失或格式不正確時
     */
    private function validateAndFormatCardFields(array $extraFields): array
    {
        // 從 extraFields 中獲取信用卡資訊
        $cardHolderName = $extraFields['card_holder_name'] ?? null;
        $cardNumber = $extraFields['card_number'] ?? null;
        $cardExpMonth = $extraFields['card_exp_mn'] ?? null;
        $cardExpYear = $extraFields['card_exp_yy'] ?? null;
        $cardCvv = $extraFields['card_cvv'] ?? null;

        // 驗證必填欄位
        if (
            empty($cardHolderName) ||
            empty($cardNumber) ||
            empty($cardExpMonth) ||
            empty($cardExpYear) ||
            empty($cardCvv)
        ) {
            throw new ApiException('Cards recharge requires card information: card_holder_name, card_number, card_exp_mn, card_exp_yy, card_cvv');
        }

        // 驗證並格式化月份：兩位數 01-12
        $month = (int) $cardExpMonth;
        if ($month < 1 || $month > 12) {
            throw new ApiException('Card expiry month must be between 01 and 12');
        }
        $formattedMonth = str_pad((string) $month, 2, '0', STR_PAD_LEFT);

        // 驗證並格式化年份：提取後兩位數字，且不小於當前年份
        $year = (int) $cardExpYear;

        // 如果輸入的是 4 位數年份（如 2025），提取後兩位
        if ($year >= 2000) {
            $year = $year % 100;  // 2025 -> 25, 2009 -> 09
        }

        // 確保是兩位數格式
        $formattedYear = str_pad((string) $year, 2, '0', STR_PAD_LEFT);

        // 驗證不小於當前年份
        $currentYear = (int) date('y');  // 當前年份後兩位
        if ($year < $currentYear) {
            throw new ApiException(sprintf('Card expiry year must be greater than or equal to %s', $currentYear));
        }

        return [
            'card_holder_name' => $cardHolderName,
            'card_number' => $cardNumber,
            'card_exp_mn' => $formattedMonth,
            'card_exp_yy' => $formattedYear,
            'card_cvv' => $cardCvv,
        ];
    }
}
