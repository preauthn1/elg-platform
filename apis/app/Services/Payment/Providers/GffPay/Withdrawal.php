<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GffPay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Illuminate\Support\Facades\Http;
use Throwable;

class Withdrawal extends AbstractMethod
{
    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'GFF';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        // 優先使用已查詢好的 order，避免重複查詢 DB
        try {
            $withdrawalOrder = $this->validatePayoutOrder($request->order);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        $transactionNo = Common::genTransactionNo($this->orderPrefix);

        // 構建請求參數
        try {
            $params = $this->buildPayoutParams($withdrawalOrder, $transactionNo);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: fn (): array => $this->sendRequest('/withdraw-order', $params),
            // 嚴格判斷：必須 code === 200 才視為成功
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['code'] ?? null) === 200,
                thirdPartyOrderNo: $result['data']['order_no'] ?? '',
                errorMessage: $result['message'] ?? 'Unknown error',
            ),
        );
    }

    /**
     * 構建代付請求參數
     */
    private function buildPayoutParams($withdrawalOrder, string $transactionNo): array
    {
        $callbackUrl = Common::withdrawalCallbackUrl($this->agentCashFlow->id);

        // 取得 currency_code (銀行代碼)
        $bankCode = $this->convertPayoutBankCode($withdrawalOrder->bank_code);
        if (!$bankCode) {
            throw new ApiException('Bank code not set / not completed. (' . $withdrawalOrder->bank_code . ')');
        }

        $params = [
            'currency_code' => $bankCode,
            'merchant_code' => $this->info['MerchantNo'],
            'merchant_order_no' => $transactionNo,
            'amount' => $withdrawalOrder->amount,
            'callback_url' => $callbackUrl,
            'address' => $withdrawalOrder->account_no,
        ];

        $params['sign'] = $this->sign($params, $this->info['MerchantKey']);

        return $params;
    }

    /**
     * 發送請求
     */
    protected function sendRequest(string $path, array $params): array
    {
        $action = 'GffPay_Withdrawal';
        $url = trim($this->info['APIUrl'], '/') . $path;

        $result = Http::logAction($action)
            ->asJson()
            ->post($url, $params)
            ->throw()
            ->json();

        return $result;
    }

    /**
     * 生成簽名
     */
    private function sign(array $input, string $secretKey): string
    {
        unset($input['sign']);
        ksort($input);
        $signString = '';
        foreach ($input as $k => $v) {
            $signString .= $k . '=' . $v . '&';
        }
        $signString = rtrim($signString, '&');
        $signString .= $secretKey;
        $sign = strtoupper(md5($signString));

        return $sign;
    }
}
