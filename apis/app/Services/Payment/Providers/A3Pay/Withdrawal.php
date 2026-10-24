<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\A3Pay;

use App\Enums\Payment\PayoutQueryOutcome;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutQueryResult;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Traits\WithdrawalPayoutTrait;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A3Pay 代付（Submit Payout）。
 *
 * Data 欄位是閘道新版命名（MID／MTransactionId／PayoutAmount／ToClient*），回應鍵 paymentOrderId；信封／AES／RSA 走 A3PayTrait。
 *
 * 代付例外分類（payment-integration §7，資損級）——文件沒有錯誤碼清單，白名單來自 2026-09-24 用測試商戶實打
 * SubmitPayout 得到的拒絕回應（HTTP 400、JSON 信封、`data.transactionId` 為 null＝未建單）：
 * - HTTP 4xx、isSuccess === false、且 errorCode 在 REJECT_CODES 內：三方受理前拒絕，可證未建單
 *   → 交 interpretResult 判 -2、訂單轉 29 可重新發起。
 * - 其餘一律結果未知 → 拋例外 → -1、訂單停 21 鎖定重送並告警：HTTP 5xx、body 非 JSON、isSuccess 非 bool、
 *   2xx 但 isSuccess=false、4xx 但 errorCode 不在白名單（文件沒列，不能證明未建單）、isSuccess=true 卻沒有單號。
 * 新碼要入白名單，先補記 api.md「錯誤碼」節的實測依據。
 *
 * 銀行碼一律經 convertPayoutBankCode() 取得；查不到直接回明確失敗（請求未發出、不建 payout 記錄）。
 *
 * @knowledge rules/provider-a3pay
 */
class Withdrawal extends AbstractMethod
{
    use A3PayTrait;
    use WithdrawalPayoutTrait;

    private const string ENDPOINT = '/api/Payout/SubmitPayout';

    /**
     * 明確拒絕白名單（api.md「錯誤碼」節，2026-09-24 實測）：
     * PMT10001 Invalid Bank Code、PMT10005 Invalid Transaction Amount（低於下限 50）、PMT10013 Insufficient Balance。
     *
     * @var list<string>
     */
    private const array REJECT_CODES = ['PMT10001', 'PMT10005', 'PMT10013'];

    private const string QUERY_ENDPOINT = '/api/Payout/GetPayoutDetail';

    /** api.md Payout Status：5 Fail */
    private const int QUERY_STATUS_FAIL = 5;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'A3PAY';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        try {
            $withdrawalOrder = $this->validatePayoutOrder($request->order);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        $bankCode = (string) $this->convertPayoutBankCode($withdrawalOrder->bank_code);

        if ($bankCode === '') {
            return $this->createFailureResponse(sprintf(
                '%s payout bank code not mapped: %s',
                $this->getDriverName(),
                (string) $withdrawalOrder->bank_code
            ));
        }

        $transactionNo = Common::genTransactionNo($this->orderPrefix);

        $params = $this->buildPayoutParams($withdrawalOrder, $transactionNo, $bankCode);

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: function () use ($params): array {
                $response = $this->sendRequestRaw(self::ENDPOINT, $params);
                $result = $response->json();

                // 5xx／非 JSON／isSuccess 非 bool：結構缺失，不能證明未建單 → 結果未知
                if ($response->serverError() || !is_array($result) || !is_bool($result['isSuccess'] ?? null)) {
                    throw new ApiException(sprintf(
                        '%s payout indeterminate response: HTTP %d',
                        $this->getDriverName(),
                        $response->status()
                    ));
                }

                // isSuccess=false 只有「HTTP 4xx ＋ errorCode 在白名單」才是可證未建單的明確拒絕；
                // 2xx 帶 false（文件未定義）或白名單外的碼都不能證明未建單 → 結果未知
                if ($result['isSuccess'] === false
                    && (!$response->clientError() || !in_array((string) ($result['errorCode'] ?? ''), self::REJECT_CODES, true))) {
                    throw new ApiException(sprintf(
                        '%s payout ambiguous rejection on HTTP %d (errorCode %s): %s',
                        $this->getDriverName(),
                        $response->status(),
                        (string) ($result['errorCode'] ?? '-'),
                        $this->describeError($result)
                    ));
                }

                // 三方說受理了卻沒給單號：已可能建單，不可判拒絕 → 結果未知（單號鍵名文件版與舊版都收）
                if ($result['isSuccess'] === true && $this->payoutOrderNo($result) === '') {
                    throw new ApiException($this->getDriverName() . ' payout accepted without paymentOrderId');
                }

                return $result;
            },
            // 到得了這裡只有兩種形狀：4xx 明確拒絕（→ -2、轉 29 可重新發起）或帶單號的受理
            interpretResult: fn (array $result): PayoutResult => $result['isSuccess'] === true
                ? new PayoutResult(isSuccess: true, thirdPartyOrderNo: $this->payoutOrderNo($result))
                : new PayoutResult(isSuccess: false, errorMessage: $this->describeError($result)),
        );
    }

    /**
     * 代付查單（api.md Payout Requery：GetPayoutDetail），供 SupportsPayoutQuery 對停 21 的單自動查證。
     *
     * 三態鐵則：只有「isSuccess=true、statusId 不是 5 Fail、且帶 paymentOrderId」才回 FOUND_ACCEPTED；
     * statusId=5 回 UNKNOWN 交人工（三方已判失敗，轉 22 只會等一個不會再來的回調）；
     * 文件沒有「查無」的錯誤碼，所以任何拒絕、連線失敗、形狀不符一律 UNKNOWN，不推斷成 NOT_FOUND。
     * 唯讀，不改訂單與記錄。
     */
    public function queryByTransactionNo(string $transactionNo): PayoutQueryResult
    {
        try {
            $response = $this->sendRequestRaw(self::QUERY_ENDPOINT, [
                'MID' => $this->info[self::FIELD_MERCHANT_CODE],
                'MTransactionId' => $transactionNo,
                'CurrCode' => $this->info[self::FIELD_CURRENCY],
            ]);
            $result = $response->json();
        } catch (Throwable $e) {
            Log::warning($this->getDriverName() . ' 代付查單失敗', [
                'transaction_no' => $transactionNo,
                'error' => $e->getMessage(),
            ]);

            return new PayoutQueryResult(PayoutQueryOutcome::UNKNOWN);
        }

        if (!$response->successful() || !is_array($result) || ($result['isSuccess'] ?? null) !== true) {
            return new PayoutQueryResult(PayoutQueryOutcome::UNKNOWN);
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        if (is_numeric($data['statusId'] ?? null) && (int) $data['statusId'] === self::QUERY_STATUS_FAIL) {
            Log::info($this->getDriverName() . ' 代付查單：三方已判失敗，交人工定案', [
                'transaction_no' => $transactionNo,
                'status' => $data['status'] ?? null,
            ]);

            return new PayoutQueryResult(PayoutQueryOutcome::UNKNOWN);
        }

        $paymentOrderId = $this->dataValue($data, 'paymentOrderId', 'transactionId');

        if (!is_scalar($paymentOrderId) || (string) $paymentOrderId === '') {
            return new PayoutQueryResult(PayoutQueryOutcome::UNKNOWN);
        }

        return new PayoutQueryResult(PayoutQueryOutcome::FOUND_ACCEPTED, (string) $paymentOrderId);
    }

    /** 代付回應的三方單號：文件鍵 paymentOrderId 優先，退回實測舊鍵 transactionId（A3PayTrait::dataValue） */
    private function payoutOrderNo(array $result): string
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $orderNo = $this->dataValue($data, 'paymentOrderId', 'transactionId');

        return is_scalar($orderNo) ? (string) $orderNo : '';
    }

    /**
     * 構建 Submit Payout 的 Data Parameter（api.md）
     */
    private function buildPayoutParams(WithdrawalOrder $withdrawalOrder, string $transactionNo, string $bankCode): array
    {
        return [
            'MID' => $this->info[self::FIELD_MERCHANT_CODE],
            'MTransactionId' => $transactionNo,
            'MCustId' => $withdrawalOrder->owner->username,
            'PayoutAmount' => sprintf('%.2f', $withdrawalOrder->amount),
            'CurrCode' => $this->info[self::FIELD_CURRENCY],
            'RequestIPAddress' => Common::getIp(),
            'ToClientBankCode' => $bankCode,
            'ToClientAccountNumber' => $withdrawalOrder->account_no ?? '',
            'ToClientAccountHolderName' => $withdrawalOrder->account_name ?? '',
            // 注意大小寫：Payout 是 CallBackURL（Payin 是 CallbackURL），照 api.md 原文
            'CallBackURL' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            // Payout Verification：三方出款前打此網址確認，由 WithdrawalQuery 回 success
            'ReturnClientURL' => Common::withdrawalQueryUrl($this->agentCashFlow->id),
            'PaymentMethodId' => 'WD',
        ];
    }
}
