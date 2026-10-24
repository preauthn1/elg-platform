<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\CCPayment;

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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 代付發起（api.md §6.1，POST /applyAppWithdrawToNetwork）。
 *
 * 成功回應只有 recordId，沒有狀態欄位——代付是否真的出款須靠 ApiWithdrawal webhook
 * 或 getAppWithdrawRecord 查證定案（api.md §6.1 註記）。
 *
 * @knowledge rules/provider-ccpayment
 */
class Withdrawal extends AbstractMethod
{
    use CCPaymentTrait;

    /**
     * 業務碼「明確拒絕」白名單：僅列可證明三方未建單者，依 api.md §3.1 錯誤碼表逐碼對照。
     * api.md 以官方 markdown 版為單一事實來源，碼的名稱與說明都取自它的錯誤碼表；只出現在
     * 廠商 GitHub SDK 文檔的碼（13005、14017–14020、14022–14024、14026）不收錄，出現時走結果未知。
     *
     * 🔴 收錄準則是「這個碼能不能證明三方沒有受理這筆代付」，不是「聽起來像不像失敗」。
     * 白名單內的碼交 interpretResult 判為同步明確拒絕（→ 訂單轉 29，可重新發起）；
     * 白名單外的一切（含未知碼）一律拋例外走結果未知（訂單停留 21 鎖定重送並告警，人工到三方後台查證後轉 22 或 29；
     * 狀態 23 已於 target/tickets#4137 廢除）。
     *
     * 🚫 以下碼刻意不收錄，收了就是把重複出款的向量打開：
     *   10001 Failed：泛用失敗，語意不明
     *   11002 Internal：系統內部錯誤，發生在請求抵達之後
     *   11003 NotFound：語意不明
     *   11004 RateLimit：無法確定攔截點在建單前或建單後
     *   11007 RepeatedSubmit：重複提交，代表前次可能已受理
     *   11011 OrderDuplicate：該訂單號已存在於三方
     *   11018 Too Many Requests：鎖與請求佇列造成的衝突，不排除已建單
     *   14027 Duplicate Sequence Number：序號重複，同 11011
     *   14028 Cannot Terminate/Cancel：語意不明
     */
    private const REJECT_CODES = [
        11000, // InvalidArgument：參數無效
        11001, // HeaderInvalidArgument：header 參數無效
        11005, // VerifySignFailed：驗簽失敗，請求在入口被拒
        11006, // ReqExpired：請求過期，未進入業務處理
        11014, // MaliciousReq：判定為惡意請求並封鎖 IP，請求被拒
        12000, // MerchantDisabled：商戶已停用
        12001, // MerchantNotFound：查無商戶
        12002, // IpNotInWhitelist：來源 IP 不在白名單
        12003, // MerchantApiDisabled：商戶未完成驗證，不可用 API
        13000, // InvalidCoin：不支援的代幣
        13001, // InvalidChain：該代幣不支援此鏈
        13004, // UnstableBlockchain：鏈不穩定，該代幣暫停提現
        14000, // BalanceInsufficient：餘額不足
        14001, // WithdrawFeeTooLow：提現網路費過低
        14002, // AddressNotActive：收款地址無效或未啟用
        14003, // AddressEmptyMemo：該鏈的 memo 不可為空
        14004, // ChainStopWithdraw：該鏈暫停提現
        14005, // WithdrawValueLessThanLimit：低於單筆下限
        14006, // WithdrawValueMoreThanLimit：高於單筆上限
        14007, // WithdrawAddrFormat：提現地址格式錯誤
        14008, // WithdrawCannotSelf：不可提現至自己的 CCPayment 地址
        14009, // CoinNoSupportMemo：該代幣不支援 memo
        14010, // NoSupportCoin：商戶不支援該代幣
        14011, // WithdrawFeeBalanceNotEnough：支付網路費的原生代幣不足
        14013, // CoinPrecisionLimit：金額超出該代幣允許的精度
        14014, // NotFinishCollection：資產歸集費用未繳清
        14015, // WithdrawToContractAddress：不支援轉入合約地址
        14016, // WithdrawInvalidAddressType：地址型別錯誤
        14025, // Invalid Memo Format：memo 格式不符鏈上要求
        14029, // Blacklisted Address：收款地址在黑名單
    ];

    protected string $orderPrefix = 'CCP';

    public function __construct(
        protected AgentCashFlow $agentCashFlow
    ) {
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        try {
            $withdrawalOrder = $this->validatePayoutOrder($request->order);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage() ?: $this->getDriverName() . ' payout order validation failed');
        }

        // 前置檢核與組參數都放 processPayout 之前：憑證缺漏、幣鏈解析失敗（三方代碼格式不符、三方後台沒啟用、
        // getCoinList 拉不到）都代表代付請求根本沒送出，直接回明確失敗，不建 payout 記錄，訂單也不會停留 21 等人工查證。
        // 憑證要在這裡先驗：幣鏈快取命中時 payoutSpec() 不會發 HTTP，憑證缺漏會拖到 processPayout 內才被擋，
        // 那時已被當成結果未知
        $missing = $this->findMissingCredentials($this->requiredRequestCredentials(), 'withdrawal');
        if ($missing !== []) {
            return $this->createFailureResponse(sprintf(
                '%s withdrawal credentials not configured: %s',
                $this->getDriverName(),
                implode(', ', $missing)
            ));
        }

        try {
            $params = $this->buildPayoutParams($withdrawalOrder);
        } catch (Throwable $e) {
            // 訊息不可為空字串：WithdrawalPayoutService::settleSyncResult() 以 errorMessage 是否為空判定金流商已受理
            return $this->createFailureResponse($e->getMessage() ?: $this->getDriverName() . ' payout params build failed');
        }

        $transactionNo = Common::genTransactionNo($this->orderPrefix);
        $params['orderId'] = $transactionNo;

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: function () use ($params): array {
                $result = $this->sendRequestRaw('/applyAppWithdrawToNetwork', $params);

                $code = $result['code'] ?? null;

                // 型別閘門：缺鍵／null／非數值＝結構缺失，不是業務拒絕，一律結果未知
                if (!is_numeric($code)) {
                    throw new ApiException($this->getDriverName() . ' payout returned unparseable business code');
                }

                $code = (int) $code;

                // 白名單外的非成功碼＝無法證明未建單 → 結果未知，訂單停留 21 鎖定重送並告警
                if ($code !== self::SUCCESS_CODE && !in_array($code, self::REJECT_CODES, true)) {
                    throw new ApiException(sprintf(
                        '%s payout ambiguous business code %d: %s',
                        $this->getDriverName(),
                        $code,
                        is_scalar($result['msg'] ?? null) ? (string) $result['msg'] : 'Unknown error'
                    ));
                }

                // 成功碼之後的解析失敗也算結果未知：三方已收單，不可回報為可重送
                if ($code === self::SUCCESS_CODE) {
                    $recordId = $result['data']['recordId'] ?? null;
                    if (!is_scalar($recordId) || (string) $recordId === '') {
                        throw new ApiException($this->getDriverName() . ' payout accepted but recordId missing');
                    }
                }

                return $result;
            },
            // 到得了這裡只有兩種形狀：白名單拒絕碼（→ 明確失敗），或成功碼（→ 已受理）
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: (int) ($result['code'] ?? 0) === self::SUCCESS_CODE,
                thirdPartyOrderNo: (string) ($result['data']['recordId'] ?? ''),
                errorMessage: is_scalar($result['msg'] ?? null) ? (string) $result['msg'] : 'Unknown error',
            ),
        );
    }

    /**
     * 以我方訂單號查代付紀錄（api.md §6.4，POST /getAppWithdrawRecord），供 SupportsPayoutQuery。
     *
     * 三態鐵則：查到紀錄且帶 recordId 才回 FOUND_ACCEPTED；業務碼成功但沒有紀錄回 NOT_FOUND；
     * HTTP 失敗、業務碼非成功、回應形狀不符一律 UNKNOWN。查詢失敗絕不推斷成 NOT_FOUND，
     * 那會打開重複出款的窗口（呼叫端對 NOT_FOUND 與 UNKNOWN 都只告警不改單）。
     */
    public function queryByOrderId(string $orderId): PayoutQueryResult
    {
        try {
            $result = $this->sendRequestRaw('/getAppWithdrawRecord', ['orderId' => $orderId]);
        } catch (Throwable $e) {
            Log::warning($this->getDriverName() . ' payout query failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return new PayoutQueryResult(PayoutQueryOutcome::UNKNOWN);
        }

        $code = $result['code'] ?? null;
        if (!is_numeric($code) || (int) $code !== self::SUCCESS_CODE) {
            return new PayoutQueryResult(PayoutQueryOutcome::UNKNOWN);
        }

        $record = $result['data']['record'] ?? null;
        if (!is_array($record)) {
            return new PayoutQueryResult(PayoutQueryOutcome::NOT_FOUND);
        }

        $recordId = $record['recordId'] ?? null;
        if (!is_scalar($recordId) || (string) $recordId === '') {
            return new PayoutQueryResult(PayoutQueryOutcome::UNKNOWN);
        }

        return new PayoutQueryResult(PayoutQueryOutcome::FOUND_ACCEPTED, (string) $recordId);
    }

    /**
     * 構建代付請求參數（orderId 由呼叫端補上）
     *
     * @return array<string, mixed>
     */
    private function buildPayoutParams(WithdrawalOrder $order): array
    {
        // 幣與鏈都取自會員所選銀行的三方代碼，格式 {幣別代號}_{三方鏈代號}（如 USDT_TRX，
        // ChannelKey::coinChain()）；coinId 執行期向 getCoinList 查，與代收同一套（CCPaymentTrait::payoutSpec()）。
        // 商戶 info 不存幣別：代付沒有支付通道，提款銀行「USDT - TRC20」本身就是一種幣加一條鏈
        $providerBankCode = $this->convertPayoutBankCode($order->bank_code);
        if (!$providerBankCode) {
            throw new ApiException(sprintf(
                '%s payout coin and chain not mapped for bank code: %s',
                $this->getDriverName(),
                $order->bank_code
            ));
        }

        $spec = $this->payoutSpec($providerBankCode);

        return [
            'coinId' => $spec['coin_id'],
            'chain' => $spec['chain'],
            // 玩家的鏈上收款地址存在提款訂單的帳號欄位（與 GffPay 加密貨幣代付一致）
            'address' => $order->account_no,
            'amount' => Common::numAdd('0', (string) $order->amount, 4),
            'notifyUrl' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            // 網路費由誰負擔：金流商戶設定 MERCHANT_PAY_NETWORK_FEE，預設 false 等於金流商預設，
            // 由會員負擔，到帳數量＝提現數量－網路費（api.md §6.1）。PM 定案後改設定不改代碼
            'merchantPayNetworkFee' => filter_var($this->info['MERCHANT_PAY_NETWORK_FEE'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
