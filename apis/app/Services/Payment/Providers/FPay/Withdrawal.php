<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\FPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

/**
 * FPay 代付發起，送 POST /merchant/withdraw_orders。
 *
 * 三方銀行碼查不到時必須在取 auth token、建代付紀錄之前直接回失敗，禁止把空的 bank_id 送給三方
 * （FPay 文件 bank_id 必填）。失敗回應會讓訂單轉代付失敗 29，可重新發起。
 * 判斷一律用 === ''，不用 empty()：convertPayoutBankCode() 查無時回空字串，而部分金流商的合法三方碼
 * 是字串 "0"（例如 PayEssence 加密貨幣代付），empty() 會把它當成空值。
 *
 * @knowledge rules/payout-submit-and-status
 */
class Withdrawal extends AbstractMethod
{
    use FPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'Fpay';

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

        $bankCode = $this->convertPayoutBankCode($withdrawalOrder->bank_code);

        if ($bankCode === '') {
            return $this->createFailureResponse(
                sprintf('Provider bank code not configured for bank: %s', $withdrawalOrder->bank_code)
            );
        }

        $transactionNo = Common::genTransactionNo($this->orderPrefix);
        $params = $this->buildPayoutParams($withdrawalOrder, $transactionNo, $bankCode);

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: fn (): array => $this->sendRequest('/merchant/withdraw_orders', $params),
            // 嚴格判斷：必須 status === true 才視為成功
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['status'] ?? null) === true,
                errorMessage: $result['message'] ?? 'Unknown error',
            ),
        );
    }

    /**
     * 構建代付請求參數
     */
    private function buildPayoutParams($withdrawalOrder, string $transactionNo, string $bankCode): array
    {
        return [
            'auth' => $this->getAuthCode(),
            'amount' => $withdrawalOrder->amount,
            'currency' => $this->agentCashFlow->currency->code,
            'orderid' => $transactionNo,
            'bank_id' => $bankCode, // 可从查詢提款銀行列表接口拿到
            'holder_name' => $withdrawalOrder->account_name, // 接收者持有人名字一定要和接收银行账号相同名字
            'account_no' => $withdrawalOrder->account_no, // 接收者银行账号
        ];
    }
}
