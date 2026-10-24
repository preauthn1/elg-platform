<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\LegendPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Illuminate\Support\Str;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use LegendPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'LEG';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 返回結果參照 API_Agent WithdrawalPayoutController@runPayoutProcess
     */
    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        // 優先使用已查詢好的 order，避免重複查詢 DB
        try {
            $withdrawalOrder = $this->validatePayoutOrder($request->order);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        // 檢查 info 是否包含必要參數
        $checkParams = ['API_URL', 'API_TOKEN', 'API_SECRET'];
        foreach ($checkParams as $param) {
            if (!isset($this->info[$param]) || $this->info[$param] === '') {
                return $this->createFailureResponse('AgentCashFlow missing parameter: ' . $param);
            }
        }

        $transactionNo = Common::genTransactionNo($this->orderPrefix);
        $extraFields = Common::safeJsonDecode($withdrawalOrder->memo);
        $params = $this->buildPayoutParams($withdrawalOrder, $transactionNo, $extraFields);
        $headers = $this->buildPayoutHeaders($params);

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: fn (): array => $this->sendRequest('/LegendPay/payout', $params, $headers),
            // 嚴格判斷：必須 code === 1000 才視為成功
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: isset($result['code']) && (int) $result['code'] === 1000,
                thirdPartyOrderNo: $result['data']['orderId'] ?? '',
                errorMessage: $result['msg'] ?? 'Unknown error',
            ),
        );
    }

    /**
     * 構建代付請求參數
     */
    private function buildPayoutParams($withdrawalOrder, string $transactionNo, array $extraFields): array
    {
        return [
            'amount' => sprintf('%.2f', $withdrawalOrder->amount), // 保留兩位小數
            'account' => $withdrawalOrder->account_no,
            'orderId' => $transactionNo,
            'callbackUrl' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            'ts' => (int) (microtime(true) * 1000), // 毫秒時間戳
            'random' => Str::random(), // 固定長度增加一致性
            'param' => $withdrawalOrder->member_id, // 商户自定义回传参数
            'personName' => $withdrawalOrder->account_name,
            'personPhone' => $extraFields['mobile'] ?? '',
            'ifsc' => $extraFields['ifsc'] ?? '', // 印度金融系統代碼
        ];
    }

    /**
     * 構建代付請求頭
     */
    private function buildPayoutHeaders(array $params): array
    {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'l-token' => $this->info['API_TOKEN'],
            'l-sign' => $this->generateSign($params, $this->info),
        ];
    }
}
