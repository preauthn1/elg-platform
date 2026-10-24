<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayMate;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Illuminate\Support\Carbon;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use PayMateTrait;

    /**
     * 信封層「明確拒絕」白名單（api.md §5.1）：商戶／參數／密文檢驗類，
     * 確定請求未進單，可安全判 -2（訂單轉 29，可重新發起）。999（系統內部錯誤）與未知碼
     * 不在此列——發生在請求抵達之後，不能排除已建單，一律視為結果未知（訂單停留 21）。
     */
    private const ENVELOPE_REJECT_CODES = [
        '101', '202', '203', '204', '205', '206',
        '301', '302', '303', '304', '401', '402', '403', '404',
    ];

    protected AgentCashFlow $agentCashFlow;

    protected string $orderPrefix = 'PM';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        try {
            $withdrawalOrder = $this->getWithdrawalOrder($request->order->id);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        $transactionNo = Common::genTransactionNo($this->orderPrefix);
        $params = $this->buildPayoutParams($withdrawalOrder, $transactionNo);

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            // 例外分類原則：只有「結構完整、驗簽通過、且業務碼明確拒絕」才交 interpretResult 判 -2；
            // 其餘（結構缺失 / 999 系統內部錯誤 / 未知碼 / 驗簽失敗 / 內層解不出）一律在此拋 → -1（結果未知，訂單停留 21）
            performRequest: function () use ($params): array {
                $envelope = $this->sendRequestRaw('/payout', $params);

                // 2xx 但信封缺 resCode＝結構缺失（WAF / CDN 頁）：無依據斷定未受理 → 結果未知
                if (!array_key_exists('resCode', $envelope)) {
                    throw new ApiException($this->getDriverName() . ' unparseable 2xx response');
                }

                // 信封層明確拒絕白名單：驗簽通過才可信（否則可能是中間層偽造，不得據此還原重送）
                if (in_array($envelope['resCode'], self::ENVELOPE_REJECT_CODES, true)) {
                    if (!$this->verifyResponseSign($envelope)) {
                        throw new ApiException($this->getDriverName() . ' rejection envelope signature verification failed');
                    }

                    return $envelope;
                }

                // resCode=999（系統內部錯誤，不能排除已建單）或未知碼：結果未知
                if ($envelope['resCode'] !== '000') {
                    throw new ApiException(sprintf('%s error: %s', $this->getDriverName(), $envelope['resMsg'] ?? 'Unknown error'));
                }

                // resCode=000：驗完整性（merchantNo / 驗簽）並解出內層；解不出＝結果未知（unwrapEnvelope 拋）
                $inner = $this->unwrapEnvelope($envelope);

                if (!array_key_exists('code', $inner)) {
                    throw new ApiException($this->getDriverName() . ' accepted envelope but payload missing code');
                }

                return $inner;
            },
            interpretResult: function (array $result): PayoutResult {
                // 信封層明確拒絕（performRequest 已過白名單＋驗簽；內層 response 無 resCode 鍵，不會誤入）
                if (array_key_exists('resCode', $result)) {
                    return new PayoutResult(
                        isSuccess: false,
                        thirdPartyOrderNo: '',
                        errorMessage: sprintf('%s error: %s', $this->getDriverName(), $result['resMsg'] ?? 'Unknown error'),
                    );
                }

                // 見 api.md 3.2 API Response：code=0000 為成功（同步受理），其他皆為三方同步拒絕
                return new PayoutResult(
                    isSuccess: ($result['code'] ?? null) === '0000',
                    thirdPartyOrderNo: $result['gbpOrderNo'] ?? '',
                    errorMessage: $result['msg'] ?? 'Unknown error',
                );
            },
        );
    }

    /**
     * 構建代付請求參數（見 api.md 3.2 API Request）
     */
    private function buildPayoutParams(WithdrawalOrder $withdrawalOrder, string $transactionNo): array
    {
        return [
            'amount' => Common::numFloor($withdrawalOrder->amount, 2),
            'bankCode' => $this->convertPayoutBankCode($withdrawalOrder->bank_code),
            'bankAccountNo' => $withdrawalOrder->account_no,
            'bankAccountName' => $withdrawalOrder->account_name,
            'orderNo' => $transactionNo,
            'orderTime' => Carbon::now()->toFrontendTime()->format('YmdHis'),
            'notifyUrl' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            'memberCode' => $withdrawalOrder->owner->username,
        ];
    }
}
