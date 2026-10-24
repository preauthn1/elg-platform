<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\SurePay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

/**
 * @knowledge rules/payout-submit-and-status
 */
class Withdrawal extends AbstractMethod
{
    use SurePayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'Sure';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
        $this->validateConfig();
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
            performRequest: fn (): array => $this->sendRequest('/payout', $params),
            // 嚴格判斷：必須 status === 1 才視為成功（In-Progress）
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['status'] ?? null) === 1,
                thirdPartyOrderNo: $result['refid'] ?? '',
                errorMessage: $this->getErrorMessage($result),
            ),
        );
    }

    /**
     * 構建代付請求參數
     */
    private function buildPayoutParams(WithdrawalOrder $withdrawalOrder, string $transactionNo): array
    {
        $callbackUrl = Common::withdrawalCallbackUrl($this->agentCashFlow->id);

        // 轉換銀行代碼
        $bankCode = $this->convertPayoutBankCode($withdrawalOrder->bank_code);
        if (empty($bankCode)) {
            throw new ApiException("Bank code not supported: {$withdrawalOrder->bank_code}");
        }

        $params = [
            'merchant' => $this->info['MERCHANT_ID'],
            'amount' => sprintf('%.2f', $withdrawalOrder->amount),
            'refid' => $transactionNo,
            'customer' => $withdrawalOrder->owner?->username ?? '',
            'currency' => $this->info['CURRENCY'],
            'bankcode' => $this->info['WITHDRAWAL_IBAN'] ?? '',
            'destbankaccname' => $withdrawalOrder->account_name,
            'destbankcode' => $bankCode,
            'destbankaccno' => $withdrawalOrder->account_no,
            'clientip' => Common::getIp(),
            'post_url' => $callbackUrl,
        ];

        // 生成簽名
        $params['token'] = $this->generateToken($params);

        return $params;
    }

    /**
     * 取得錯誤訊息（客服在後台看到的就是這串，也會寫進代付紀錄 info_1）
     *
     * 錯誤表有的碼回表內文字；錯誤表沒有的碼一律附上金流商回傳的 statdesc，
     * 例：-110 → "Unknown error code: -110 (Invalid Credential)"，客服不必翻 DB 就看得到原因（target/op_tickets#3175）。
     * 官方文檔沒列、但實測回傳過的碼，記在 docs/integrations/payments/SurePay/api.md「代付 API」一節。
     *
     * ! 回傳值禁止為空字串：WithdrawalPayoutService::settleSyncResult() 以 errorMessage 是否為空判定金流商已受理，
     * ! 空字串會把同步拒絕誤判成受理（提款單轉 22 等回調）。表外碼必須保留 "Unknown error code: <碼>" 前綴，
     * ! statdesc 缺漏、不是字串或只有空白時只回前綴。
     *
     * @param  array<mixed>  $result  金流商的代付回應
     */
    private function getErrorMessage(array $result): string
    {
        $errorCode = (int) ($result['status'] ?? -999);

        $errorMessages = [
            1 => 'In-Progress',
            -101 => 'Insufficient Balance',
            -102 => 'Withdrawal Bank Not Found',
            -103 => 'Invalid Request',
            -104 => 'Request Rejected',
            -106 => 'Dest Bank Blacklisted',
            -107 => 'Invalid Merchant',
            -108 => 'Invalid Token',
            -999 => 'Unknown Error',
        ];

        if (isset($errorMessages[$errorCode])) {
            return $errorMessages[$errorCode];
        }

        $statdesc = $result['statdesc'] ?? null;
        $reason = is_string($statdesc) ? trim($statdesc) : '';

        return $reason === ''
            ? sprintf('Unknown error code: %d', $errorCode)
            : sprintf('Unknown error code: %d (%s)', $errorCode, $reason);
    }
}
