<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GMPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

/**
 * GMPay 代付發起（standalone，不繼承 MajesticPay）。
 *
 * 送 POST /api/create_payout，發起簽名走 GMPayTrait::generateHash
 * （ref_no + number_format(amount,2) + currency）。
 *
 * 代付流水號前綴 'GMP'（GMPay 專屬）。拆分前沿用繼承來的 'MAJ'，解耦後正名為 'GMP'
 * 以與 MajesticPay 區隔；僅影響新代付的 transaction_no，回調配對用已存值故在途舊單不受影響。
 *
 * 三方銀行碼查不到時必須在建代付紀錄之前直接回失敗，禁止把空的 bank_name 送給三方
 * （GMPay 文件 bank_name 必填，值是收款銀行代碼）。失敗回應會讓訂單轉代付失敗 29，可重新發起。
 * 判斷一律用 === ''，不用 empty()：convertPayoutBankCode() 查無時回空字串，而部分金流商的合法三方碼
 * 是字串 "0"（例如 PayEssence 加密貨幣代付），empty() 會把它當成空值。
 *
 * @knowledge rules/payout-submit-and-status
 */
class Withdrawal extends AbstractMethod
{
    use GMPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'GMP';

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
     *
     * @return array<string, mixed>
     */
    private function buildPayoutParams($withdrawalOrder, string $transactionNo, string $bankCode): array
    {
        return [
            'api_key' => $this->info['API_KEY'],
            'ref_no' => $transactionNo,
            'amount' => $withdrawalOrder->amount,
            'currency' => $this->info['CURRENCY'],
            'callback_url' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            'bank_name' => $bankCode,
            'bank_holder_name' => $withdrawalOrder->account_name,
            'bank_account' => $withdrawalOrder->account_no,
        ];
    }
}
