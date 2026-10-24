<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BillionPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Illuminate\Support\Arr;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use BillionPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'Billion';

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
        $params = $this->buildPayoutParams($withdrawalOrder, $transactionNo);

        // 前置登入：失敗＝代付請求未發出，直接明確失敗
        // （不建 payout 記錄；訂單轉 29，與明確拒絕同路徑，可重新發起）
        try {
            $this->login();
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        $response = $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            // 登入已前置完成；此處只剩代付請求本體（連線/HTTP 例外＝結果未知 → 訂單停留 21）
            performRequest: fn (): array => $this->sendRequest($this->info['WITHDRAWAL_URL'], $params),
            // 嚴格判斷：必須有 internal_id 才視為成功
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: Arr::has($result, ['internal_id']),
                thirdPartyOrderNo: (string) ($result['internal_id'] ?? ''),
                errorMessage: $result['message'] ?? 'Unknown error',
            ),
        );

        // 同步受理：回填三方單號至訂單（保留原行為）
        if ($response->isSuccess && !empty($response->providerOrderNo)) {
            $withdrawalOrder->update(['third_party_order_no' => $response->providerOrderNo]);
        }

        return $response;
    }

    /**
     * 構建代付請求參數
     */
    private function buildPayoutParams(WithdrawalOrder $withdrawalOrder, string $transactionNo): array
    {
        $params = [
            'transaction_id' => $transactionNo,
            'callback_url' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            'currency' => 'SGD',
            'customer_id' => $withdrawalOrder->owner->username,
            'amount' => $withdrawalOrder->amount, // ! 不能有小數點
            'method' => 'card',
            'bank' => $this->convertPayoutBankCode($withdrawalOrder->bank_code), // 三方銀行代碼
            'bank_account_name' => $withdrawalOrder->account_name, // 會員銀行收款人名稱
            'bank_account_number' => $withdrawalOrder->account_no, // 會員銀行帳號
        ];

        $params[$this->signField] = $this->generateSign($params, $this->info['SECRET_KEY']);

        return $params;
    }
}
