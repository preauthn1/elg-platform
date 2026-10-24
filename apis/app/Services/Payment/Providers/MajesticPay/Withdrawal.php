<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\MajesticPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use MajesticPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'MAJ';

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
        $params['hash'] = $this->generateHash($params, $this->info['SECRET_KEY']);

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: fn (): array => $this->sendRequest('/api/create_payout', $params),
            // 嚴格判斷：必須 status === true 才視為成功
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['status'] ?? null) === true,
                thirdPartyOrderNo: $result['result']['transaction_reference'] ?? '',
                // 從結果中取得錯誤訊息，若無則序列化整個結果
                errorMessage: (isset($result['message']) && is_string($result['message']))
                    ? $result['message']
                    : Common::safeJsonEncode($result),
            ),
        );
    }

    /**
     * 構建代付請求參數
     */
    private function buildPayoutParams($withdrawalOrder, string $transactionNo): array
    {
        return [
            'api_key' => $this->info['API_KEY'],
            'ref_no' => $transactionNo,
            'amount' => $withdrawalOrder->amount,
            'currency' => $this->info['CURRENCY'],
            'callback_url' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            'bank_name' => $this->convertPayoutBankCode($withdrawalOrder->bank_code),
            'bank_holder_name' => $withdrawalOrder->account_name,
            'bank_account' => $withdrawalOrder->account_no,
        ];
    }
}
